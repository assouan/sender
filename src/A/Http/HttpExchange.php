<?php

declare(strict_types=1);

namespace A\Http;

use Fiber;

class HttpExchange
{
    protected(set) Request $request;

    protected(set) ?Response $response = null;

    protected(set) ?\Throwable $error = null;

    protected(set) bool $done = false;

    public bool $failed { get { return $this->error !== null; } }

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function complete(Response $response) : void
    {
        if ($this->done)
        {
            return;
        }

        $this->response = $response;
        $this->done = true;
    }

    public function fail(\Throwable $error) : void
    {
        if ($this->done)
        {
            return;
        }

        $this->error = $error;
        $this->done = true;
    }

    public function await() : static
    {
        while (!$this->done)
        {
            Fiber::suspend();
        }

        if ($this->error !== null)
        {
            throw $this->error;
        }

        return $this;
    }
}
