<?php

declare(strict_types=1);

namespace A\Http;

use A\Http\Protocol\Http1\Connection as Http1Connection;
use A\Http\Protocol\Http2\Connection as Http2Connection;
use A\Promise;
use Closure;

class HttpClient
{
    protected(set) Headers $headers;

    protected Closure $connection_factory;

    protected array $connections = [];

    public function __construct(Headers|array $headers = [], ?callable $connection_factory = null, ?Proxy $proxy = null)
    {
        if ($connection_factory !== null and $proxy !== null)
        {
            throw new \InvalidArgumentException('HttpClient cannot use both connection_factory and proxy.');
        }

        $this->headers = new Headers();

        foreach ($headers instanceof Headers ? $headers : new Headers($headers) as $header)
        {
            $this->headers->set($header);
        }

        $this->connection_factory = $connection_factory === null
            ? static fn (Request $request) : Http1Connection|Http2Connection => static::create_connection($request, $proxy)
            : Closure::fromCallable($connection_factory);
    }

    public function get(string $url, Headers|array $headers = [], string $version = '1.1') : Promise
    {
        return $this->request('GET', $url, $headers, '', $version);
    }

    public function post(string $url, Headers|array $headers = [], string $body = '', string $version = '1.1') : Promise
    {
        return $this->request('POST', $url, $headers, $body, $version);
    }

    public function request(string $method, string $url, Headers|array $headers = [], string $body = '', string $version = '1.1') : Promise
    {
        return $this->send(new Request($method, $url, $version, $headers, $body));
    }

    public function send(Request $request) : Promise
    {
        return async(function () use ($request) : Response
        {
            if (!in_array($request->scheme, ['http', 'https'], true))
            {
                throw new \InvalidArgumentException("Unsupported HTTP scheme: {$request->scheme}");
            }

            $request = $this->prepare($request);
            $exchange = $this->connection($request)->send($request)->await();

            if ($exchange->response === null)
            {
                throw new \RuntimeException('HTTP exchange completed without response.');
            }

            return $exchange->response;
        });
    }

    public function close() : void
    {
        foreach ($this->connections as $connection)
        {
            $connection->close();
        }

        $this->connections = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function connection(Request $request) : Http1Connection|Http2Connection
    {
        $protocol = str_starts_with($request->version, '2') ? ($request->scheme === 'https' ? 'h2' : 'h2c') : 'http1';
        $key = "{$protocol}://{$request->host}:{$request->port}";

        if (isset($this->connections[$key]) && $this->connections[$key]->available)
        {
            return $this->connections[$key];
        }

        $connection = ($this->connection_factory)($request);

        if (!$connection instanceof Http1Connection && !$connection instanceof Http2Connection)
        {
            throw new \RuntimeException('The HTTP connection factory must return a HTTP protocol connection.');
        }

        return $this->connections[$key] = $connection;
    }

    protected static function create_connection(Request $request, ?Proxy $proxy = null) : Http1Connection|Http2Connection
    {
        return str_starts_with($request->version, '2')
            ? Http2Connection::for_request($request, $proxy)
            : Http1Connection::for_request($request, $proxy);
    }

    protected function prepare(Request $request) : Request
    {
        $headers = new Headers($this->headers);

        foreach ($request->headers as $header)
        {
            $headers->set($header);
        }

        return new Request($request->method, $request->url, $request->version, $headers, $request->body);
    }
}
