<?php

declare(strict_types=1);

namespace A\Http\Protocol\Http2;

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
    protected const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    protected(set) TcpSocket $socket;

    protected(set) string $host = '';

    protected(set) int $port = 0;

    protected(set) bool $closed = false;

    protected bool $started = false;

    protected int $next_stream_id = 1;

    protected string $buffer = '';

    protected Hpack $hpack;

    protected array $pending = [];

    protected array $responses = [];

    public bool $connected { get { return $this->socket->is_connected; } }

    public bool $available { get { return !$this->closed && $this->connected; } }

    public static function for_request(Request $request, ?Proxy $proxy = null) : static
    {
        return new static(static::socket_for_request($request, $proxy));
    }

    public function __construct(?TcpSocket $socket = null)
    {
        $this->socket = $socket ?? new TcpSocket();
        $this->hpack = new Hpack();

        $this->socket->on_packet->connect(function (string $packet) : void
        {
            $this->buffer .= $packet;
            $this->drain();
        });

        $this->socket->on_disconnect->connect(function () : void
        {
            $this->closed = true;
            $this->fail_all(new \RuntimeException('HTTP/2 connection closed.'));
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
            $request = new Request($request->method, $request->url, '2.0', new Headers($request->headers), $request->body);
            $exchange = new HttpExchange($request);

            if (!$this->connected)
            {
                if (!$this->connect($request->host, $request->port))
                {
                    throw new \RuntimeException("Could not connect to {$request->host}:{$request->port}.");
                }
            }

            $this->start();

            $stream_id = $this->next_stream_id;
            $this->next_stream_id += 2;
            $this->pending[$stream_id] = $exchange;

            $flags = Frame::END_HEADERS | ($request->body === '' ? Frame::END_STREAM : 0);
            $this->write(Frame::encode(Frame::HEADERS, $flags, $stream_id, $this->header_block($request)));

            if ($request->body !== '')
            {
                $this->write(Frame::encode(Frame::DATA, Frame::END_STREAM, $stream_id, $request->body));
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

    protected function start() : void
    {
        if ($this->started)
        {
            return;
        }

        $this->write(static::PREFACE . Frame::encode(Frame::SETTINGS, 0, 0));
        $this->started = true;
    }

    protected static function socket_for_request(Request $request, ?Proxy $proxy = null) : TcpSocket
    {
        if ($proxy !== null)
        {
            return $proxy->socket_for($request, 'http2');
        }

        if ($request->scheme === 'https')
        {
            return new TlsSocket(['alpn_protocols' => 'h2']);
        }

        return new TcpSocket();
    }

    protected function write(string $packet) : void
    {
        if (!$this->socket->send_packet($packet))
        {
            throw new \RuntimeException('Could not send the complete HTTP/2 packet.');
        }
    }

    protected function drain() : void
    {
        while (($packet = Frame::try_decode($this->buffer)) !== null)
        {
            $frame = $packet[0];
            $this->buffer = $packet[1];
            $this->receive($frame);
        }
    }

    protected function receive(Frame $frame) : void
    {
        if ($frame->type === Frame::SETTINGS)
        {
            if (($frame->flags & Frame::ACK) === 0)
            {
                $this->write(Frame::encode(Frame::SETTINGS, Frame::ACK, 0));
            }

            return;
        }

        if ($frame->type === Frame::HEADERS)
        {
            $this->receive_headers($frame);
            return;
        }

        if ($frame->type === Frame::DATA)
        {
            $this->receive_data($frame);
            return;
        }

        if ($frame->type === Frame::RST_STREAM)
        {
            $this->fail_stream($frame->stream_id, new \RuntimeException('HTTP/2 stream was reset.'));
            return;
        }

        if ($frame->type === Frame::GOAWAY)
        {
            $this->closed = true;
            $this->fail_all(new \RuntimeException('HTTP/2 connection received GOAWAY.'));
        }
    }

    protected function receive_headers(Frame $frame) : void
    {
        if (($frame->flags & Frame::END_HEADERS) === 0)
        {
            $this->fail_stream($frame->stream_id, new \RuntimeException('HTTP/2 CONTINUATION frames are not supported yet.'));
            return;
        }

        $status = 0;
        $headers = new Headers();

        try
        {
            $decoded = $this->hpack->decode($frame->payload);
        }
        catch (\Throwable $error)
        {
            $this->fail_stream($frame->stream_id, $error);

            return;
        }

        foreach ($decoded as $header)
        {
            [$name, $value] = $header;

            if ($name === ':status')
            {
                $status = (int)$value;
                continue;
            }

            if (!str_starts_with($name, ':'))
            {
                $headers->add($name, $value);
            }
        }

        $this->responses[$frame->stream_id] = new Response('2.0', $status, '', $headers);

        if (($frame->flags & Frame::END_STREAM) !== 0)
        {
            $this->complete_stream($frame->stream_id);
        }
    }

    protected function receive_data(Frame $frame) : void
    {
        $response = $this->responses[$frame->stream_id] ?? null;

        if ($response === null)
        {
            $this->fail_stream($frame->stream_id, new \RuntimeException('HTTP/2 DATA received before response headers.'));
            return;
        }

        $response->body .= $frame->payload;

        if (($frame->flags & Frame::END_STREAM) !== 0)
        {
            $this->complete_stream($frame->stream_id);
        }
    }

    protected function complete_stream(int $stream_id) : void
    {
        $exchange = $this->pending[$stream_id] ?? null;
        $response = $this->responses[$stream_id] ?? null;

        unset($this->pending[$stream_id], $this->responses[$stream_id]);

        if ($exchange !== null && $response !== null)
        {
            $exchange->complete($response);
        }
    }

    protected function fail_stream(int $stream_id, \Throwable $error) : void
    {
        $exchange = $this->pending[$stream_id] ?? null;

        unset($this->pending[$stream_id], $this->responses[$stream_id]);

        if ($exchange !== null)
        {
            $exchange->fail($error);
        }
    }

    protected function fail_all(\Throwable $error) : void
    {
        foreach ($this->pending as $exchange)
        {
            $exchange->fail($error);
        }

        $this->pending = [];
        $this->responses = [];
    }

    protected function header_block(Request $request) : string
    {
        $headers = [
            ':method' => $request->method,
            ':scheme' => $request->scheme,
            ':authority' => $this->authority($request),
            ':path' => $request->target(),
        ];

        foreach ($request->headers as $header)
        {
            $name = strtolower($header->name);

            if (in_array($name, ['host', 'connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade'], true))
            {
                continue;
            }

            $headers[$name] = $header->values;
        }

        return $this->hpack->encode($headers);
    }

    protected function authority(Request $request) : string
    {
        if (($request->scheme === 'http' && $request->port !== 80) || ($request->scheme === 'https' && $request->port !== 443))
        {
            return "{$request->host}:{$request->port}";
        }

        return $request->host;
    }
}
