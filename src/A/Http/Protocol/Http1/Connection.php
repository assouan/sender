<?php

declare(strict_types=1);

namespace A\Http\Protocol\Http1;

use A\Http\Headers;
use A\Http\HttpExchange;
use A\Http\Proxy;
use A\Http\Request;
use A\Http\Response;
use A\Network\TcpSocket;
use A\Network\TlsSocket;
use A\Promise;

class Connection
{
    protected(set) TcpSocket $socket;

    protected(set) string $host = '';

    protected(set) int $port = 0;

    protected(set) bool $closed = false;

    protected array $pending = [];

    protected string $buffer = '';

    public bool $connected { get { return $this->socket->is_connected; } }

    public bool $available { get { return !$this->closed && $this->connected; } }

    public static function for_request(Request $request, ?Proxy $proxy = null) : static
    {
        return new static(static::socket_for_request($request, $proxy));
    }

    public function __construct(?TcpSocket $socket = null)
    {
        $this->socket = $socket ?? new TcpSocket();

        $this->socket->on_packet->connect(function (string $packet) : void
        {
            $this->buffer .= $packet;
            $this->drain();
        });

        $this->socket->on_disconnect->connect(function () : void
        {
            $this->closed = true;
            $this->drain_closed();
        });

        $this->socket->on_error->connect(function (\Throwable $error) : void
        {
            $this->closed = true;
            $this->fail_all($error);
        });
    }

    public function send(Request $request) : Promise
    {
        return async(function () use ($request) : HttpExchange
        {
            $request = $this->prepare($request);
            $exchange = new HttpExchange($request);

            if (!$this->connected)
            {
                if (!$this->connect($request->host, $request->port))
                {
                    throw new \RuntimeException("Could not connect to {$request->host}:{$request->port}.");
                }
            }

            $this->pending[] = $exchange;
            $packet = $request->to_packet();

            if (!$this->socket->send_packet($packet))
            {
                array_pop($this->pending);
                $exchange->fail(new \RuntimeException('Could not send the complete HTTP request.'));
            }

            return $exchange->await();
        });
    }

    public function connect(string $host, int $port) : bool
    {
        if ($this->connected)
        {
            return $this->host === $host && $this->port === $port;
        }

        if ($this->closed)
        {
            return false;
        }

        if (!$this->socket->connect($host, $port))
        {
            $this->closed = true;

            return false;
        }

        $this->host = $host;
        $this->port = $port;

        return true;
    }

    public function close() : void
    {
        $this->closed = true;
        $this->socket->disconnect();
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function prepare(Request $request) : Request
    {
        $headers = new Headers($request->headers);

        if ($request->version === '1.1' && !$headers->has('host'))
        {
            $headers['Host'] = $this->host_header($request);
        }

        if ($request->body !== '' && !$headers->has('content-length') && !$headers->has('transfer-encoding'))
        {
            $headers['Content-Length'] = (string)$request->content_length;
        }

        return new Request($request->method, $request->url, $request->version, $headers, $request->body);
    }

    protected static function socket_for_request(Request $request, ?Proxy $proxy = null) : TcpSocket
    {
        if ($proxy !== null)
        {
            return $proxy->socket_for($request, 'http1');
        }

        if ($request->scheme === 'https')
        {
            return new TlsSocket(['alpn_protocols' => 'http/1.1']);
        }

        return new TcpSocket();
    }

    protected function drain() : void
    {
        while ($this->pending)
        {
            $exchange = $this->pending[0];
            $packet = Response::try_parse_packet($this->buffer, $exchange->request->method !== 'HEAD');

            if ($packet === null)
            {
                return;
            }

            $response = $packet[0];
            $this->buffer = $packet[1];
            array_shift($this->pending)->complete($response);

            if ($this->should_close($exchange))
            {
                $this->close();
                return;
            }
        }
    }

    protected function drain_closed() : void
    {
        if ($this->pending && $this->buffer !== '')
        {
            $exchange = $this->pending[0];

            try
            {
                $packet = Response::parse_packet($this->buffer, $exchange->request->method !== 'HEAD');
                $response = $packet[0];
                $this->buffer = $packet[1];
                array_shift($this->pending)->complete($response);
            }
            catch (\Throwable $error)
            {
                array_shift($this->pending)->fail($error);
            }
        }

        $this->fail_all(new \RuntimeException('HTTP connection closed.'));
    }

    protected function fail_all(\Throwable $error) : void
    {
        foreach ($this->pending as $exchange)
        {
            $exchange->fail($error);
        }

        $this->pending = [];
    }

    protected function should_close(HttpExchange $exchange) : bool
    {
        $response = $exchange->response;

        if ($response === null)
        {
            return true;
        }

        $request_connection = strtolower($exchange->request->headers->value('connection', '') ?? '');
        $response_connection = strtolower($response->headers->value('connection', '') ?? '');

        if (str_contains($request_connection, 'close') || str_contains($response_connection, 'close'))
        {
            return true;
        }

        if ($response->version === '1.0')
        {
            return !str_contains($request_connection, 'keep-alive') && !str_contains($response_connection, 'keep-alive');
        }

        return false;
    }

    protected function host_header(Request $request) : string
    {
        $host = $request->host;

        if (($request->scheme === 'http' && $request->port !== 80) || ($request->scheme === 'https' && $request->port !== 443))
        {
            return "{$host}:{$request->port}";
        }

        return $host;
    }
}
