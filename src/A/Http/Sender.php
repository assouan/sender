<?php

namespace A\Http;

class Sender
{
    public static function send(Response $response) : void
    {
        if (!headers_sent())
        {
            header(sprintf(
                'HTTP/%s %d%s',
                $response->version,
                $response->status,
                $response->reason ? ' ' . $response->reason : ''
            ));

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

        echo $response->body;
    }
}
