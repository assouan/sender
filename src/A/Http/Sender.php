<?php

declare(strict_types=1);

namespace A\Http;

class Sender
{
    public static function send(Response $response) : void
    {
        if (!headers_sent())
        {
            static::send_status($response);
            static::send_headers($response);
        }

        static::send_body($response);
    }

    public static function send_status(Response $response) : void
    {
        header(sprintf(
            'HTTP/%s %d%s',
            $response->version,
            $response->status,
            $response->reason ? ' ' . $response->reason : ''
        ));
    }

    public static function send_headers(Response $response) : void
    {
        foreach ($response->headers as $header)
        {
            $replace = true;

            foreach ($header->values as $value)
            {
                header(sprintf('%s: %s', $header->name, $value), $replace);
                $replace = false;
            }
        }
    }

    public static function send_body(Response $response) : void
    {
        echo $response->body;
    }
}
