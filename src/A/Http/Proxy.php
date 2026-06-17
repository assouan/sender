<?php

declare(strict_types=1);

namespace A\Http;

use A\Network\TcpSocket;
use A\Proxy\HttpProxySocket;
use A\Proxy\HttpsProxySocket;
use A\Proxy\Socks4ProxySocket;
use A\Proxy\Socks5ProxySocket;

class Proxy
{
    protected const HTTP = 'http';
    protected const SOCKS4 = 'socks4';
    protected const SOCKS5 = 'socks5';

    protected function __construct(
        protected(set) string $type,
        protected(set) string $host,
        protected(set) int $port,
        protected(set) ?string $username = null,
        protected(set) ?string $password = null,
        protected(set) bool $verify_peer = true,
        protected(set) bool $verify_peer_name = true,
        protected(set) bool $allow_self_signed = false,
    ) {
    }

    public static function http(
        string $host,
        int $port = 8080,
        ?string $username = null,
        ?string $password = null,
        bool $verify_peer = true,
        bool $verify_peer_name = true,
        bool $allow_self_signed = false,
    ) : static {
        return new static(static::HTTP, $host, $port, $username, $password, $verify_peer, $verify_peer_name, $allow_self_signed);
    }

    public static function socks4(
        string $host,
        int $port = 1080,
        ?string $username = null,
    ) : static {
        return new static(static::SOCKS4, $host, $port, $username);
    }

    public static function socks5(
        string $host,
        int $port = 1080,
        ?string $username = null,
        ?string $password = null,
    ) : static {
        return new static(static::SOCKS5, $host, $port, $username, $password);
    }

    public function socket_for(Request $request, string $protocol) : TcpSocket
    {
        return match ($this->type)
        {
            static::HTTP => $this->http_socket_for($request, $protocol),
            static::SOCKS4 => $this->socks4_socket_for($request),
            static::SOCKS5 => $this->socks5_socket_for($request),
            default => throw new \RuntimeException("Unsupported proxy type: {$this->type}"),
        };
    }

    protected function http_socket_for(Request $request, string $protocol) : TcpSocket
    {
        if ($request->scheme === 'https')
        {
            return new HttpsProxySocket(
                $this->host,
                $this->port,
                $this->username,
                $this->password,
                options: [
                    'verify_peer' => $this->verify_peer,
                    'verify_peer_name' => $this->verify_peer_name,
                    'allow_self_signed' => $this->allow_self_signed,
                    'alpn_protocols' => $protocol === 'http2' ? 'h2' : 'http/1.1',
                ],
            );
        }

        return new HttpProxySocket($this->host, $this->port, $this->username, $this->password);
    }

    protected function socks4_socket_for(Request $request) : TcpSocket
    {
        if ($request->scheme === 'https')
        {
            throw new \RuntimeException('HTTPS requests through SOCKS4 proxies are not supported yet.');
        }

        return new Socks4ProxySocket($this->host, $this->port, $this->username);
    }

    protected function socks5_socket_for(Request $request) : TcpSocket
    {
        if ($request->scheme === 'https')
        {
            throw new \RuntimeException('HTTPS requests through SOCKS5 proxies are not supported yet.');
        }

        return new Socks5ProxySocket($this->host, $this->port, $this->username, $this->password);
    }
}
