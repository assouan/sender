<?php

declare(strict_types=1);

namespace A\Http;

use A\Promise;
use A\Proxy\ProxyConfig;
use Fiber;

class CurlClient
{
    protected(set) Headers $headers;

    protected(set) array $options = [];

    protected ?\CurlMultiHandle $multi = null;

    protected ?Promise $selector_promise = null;

    protected array $transfers = [];

    protected array $results = [];

    public function __construct(array $options = [], ?ProxyConfig $proxy_config = null, Headers|array $headers = [])
    {
        if (!extension_loaded('curl'))
        {
            throw new \RuntimeException('The curl extension is required to use CurlClient.');
        }

        $this->headers = $headers instanceof Headers ? $headers : new Headers($headers);
        $this->set_options($options);

        if ($proxy_config !== null)
        {
            $this->set_options($proxy_config->to_curlopt());
        }
    }

    public function set_option(int $option, mixed $value) : static
    {
        $this->options[$option] = $value;

        return $this;
    }

    public function set_options(array $options) : static
    {
        foreach ($options as $option => $value)
        {
            $this->set_option((int)$option, $value);
        }

        return $this;
    }

    public function get(string $url, Headers|array $headers = [], string $version = '1.1') : Promise|Response
    {
        return $this->request('GET', $url, $headers, '', $version);
    }

    public function post(string $url, Headers|array $headers = [], string $body = '', string $version = '1.1') : Promise|Response
    {
        return $this->request('POST', $url, $headers, $body, $version);
    }

    public function request(string $method, string $url, Headers|array $headers = [], string $body = '', string $version = '1.1') : Promise|Response
    {
        return $this->send_request(new Request($method, $url, $version, $headers, $body));
    }

    public function get_json(string $url, Headers|array $headers = [], string $version = '1.1') : Promise|JsonResponse
    {
        return $this->request_json('GET', $url, null, $headers, $version);
    }

    public function post_json(string $url, mixed $json = null, Headers|array $headers = [], string $version = '1.1') : Promise|JsonResponse
    {
        return $this->request_json('POST', $url, $json, $headers, $version);
    }

    public function request_json(string $method, string $url, mixed $json = null, Headers|array $headers = [], string $version = '1.1') : Promise|JsonResponse
    {
        $headers = $headers instanceof Headers ? new Headers($headers) : new Headers($headers);
        $headers->set('Accept', 'application/json');
        $body = '';

        if ($json !== null)
        {
            $body = json_encode($json, JSON_THROW_ON_ERROR);
            $headers->set('Content-Type', 'application/json');
        }

        $promise = $this->send_request(new Request($method, $url, $version, $headers, $body));

        return async(static function () use ($promise) : JsonResponse
        {
            return JsonResponse::from_response($promise->await());
        });
    }

    public function send_request(Request $request) : Promise|Response
    {
        try
        {
            $id = $this->enqueue($request);
        }
        catch (\Throwable $error)
        {
            $id = $this->store_result($error);
        }

        return async(function () use ($id) : Response
        {
            while (!array_key_exists($id, $this->results))
            {
                Fiber::suspend();
            }

            $result = $this->results[$id];
            unset($this->results[$id]);

            if ($result instanceof \Throwable)
            {
                throw $result;
            }

            return $result;
        });
    }

    public function close() : void
    {
        foreach ($this->transfers as $id => $transfer)
        {
            curl_multi_remove_handle($this->multi(), $transfer->handle);
            curl_close($transfer->handle);
            $this->results[$id] = new \RuntimeException('CurlClient closed before the response completed.');
        }

        $this->transfers = [];

        if ($this->multi !== null)
        {
            curl_multi_close($this->multi);
            $this->multi = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    protected function enqueue(Request $request) : int
    {
        if (!in_array($request->scheme, ['http', 'https'], true))
        {
            $this->results[] = new \InvalidArgumentException("Unsupported HTTP scheme: {$request->scheme}");

            return array_key_last($this->results);
        }

        $handle = curl_init($this->curl_url($request));

        if (!$handle instanceof \CurlHandle)
        {
            throw new \RuntimeException('Unable to create a curl handle.');
        }

        $id = spl_object_id($handle);
        $transfer = (object)[
            'handle' => $handle,
            'request' => $request,
            'header' => '',
            'headers' => [],
            'body' => '',
        ];

        try
        {
            $this->configure_handle($handle, $request, $transfer);
        }
        catch (\Throwable $error)
        {
            curl_close($handle);

            throw $error;
        }

        $this->transfers[$id] = $transfer;

        $status = curl_multi_add_handle($this->multi(), $handle);

        if ($status !== CURLM_OK)
        {
            unset($this->transfers[$id]);
            curl_close($handle);

            throw new \RuntimeException(curl_multi_strerror($status), $status);
        }

        $this->ensure_selector();

        return $id;
    }

    protected function configure_handle(\CurlHandle $handle, Request $request, object $transfer) : void
    {
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
        ] + $this->options;

        $options[CURLOPT_CUSTOMREQUEST] = $request->method;
        $options[CURLOPT_HTTP_VERSION] = $this->curl_http_version($request->version);
        $options[CURLOPT_HTTPHEADER] = $this->curl_headers($request);
        $options[CURLOPT_HEADERFUNCTION] = static function (\CurlHandle $handle, string $line) use ($transfer) : int
        {
            $transfer->header .= $line;

            if (trim($line) === '')
            {
                $transfer->headers[] = $transfer->header;
                $transfer->header = '';
            }

            return strlen($line);
        };
        $options[CURLOPT_WRITEFUNCTION] = static function (\CurlHandle $handle, string $chunk) use ($transfer) : int
        {
            $transfer->body .= $chunk;

            return strlen($chunk);
        };

        if ($request->body !== '')
        {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if ($request->method === 'HEAD')
        {
            $options[CURLOPT_NOBODY] = true;
        }

        if (!curl_setopt_array($handle, $options))
        {
            throw new \RuntimeException(curl_error($handle) ?: 'Unable to configure curl handle.');
        }
    }

    protected function ensure_selector() : void
    {
        $this->selector_promise ??= async(function () : void
        {
            try
            {
                while ($this->transfers)
                {
                    $this->selector_tick();
                    Fiber::suspend();
                }
            }
            finally
            {
                $this->selector_promise = null;
            }
        });
    }

    protected function selector_tick() : void
    {
        do
        {
            $status = curl_multi_exec($this->multi(), $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($status !== CURLM_OK)
        {
            $this->fail_all(new \RuntimeException(curl_multi_strerror($status), $status));

            return;
        }

        while ($info = curl_multi_info_read($this->multi()))
        {
            $this->complete_transfer($info);
        }

        if ($running > 0)
        {
            curl_multi_select($this->multi(), 0.0);
        }
    }

    protected function complete_transfer(array $info) : void
    {
        $handle = $info['handle'];
        $id = spl_object_id($handle);
        $transfer = $this->transfers[$id] ?? null;

        if ($transfer === null)
        {
            return;
        }

        curl_multi_remove_handle($this->multi(), $handle);

        if ($info['result'] !== CURLE_OK)
        {
            $message = curl_error($handle) ?: curl_strerror($info['result']);
            $this->results[$id] = new \RuntimeException($message, $info['result']);
        }
        else
        {
            try
            {
                $this->results[$id] = $this->response_from_transfer($transfer);
            }
            catch (\Throwable $error)
            {
                $this->results[$id] = $error;
            }
        }

        curl_close($handle);
        unset($this->transfers[$id]);
    }

    protected function response_from_transfer(object $transfer) : Response
    {
        $header = $this->last_header($transfer->headers);

        if ($header === '')
        {
            return new Response(
                $this->curl_response_version($transfer->handle),
                (int)curl_getinfo($transfer->handle, CURLINFO_RESPONSE_CODE),
                '',
                [],
                $transfer->body,
            );
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($header)) ?: [];
        $status_line = array_shift($lines);

        if (!is_string($status_line) or !preg_match('/^HTTP\/(\d+(?:\.\d+)?)\s+(\d{3})(?:\s+(.*))?$/', $status_line, $match))
        {
            throw new \RuntimeException("Invalid HTTP response line: {$status_line}");
        }

        return new Response($match[1], (int)$match[2], $match[3] ?? '', Headers::parse($lines), $transfer->body);
    }

    protected function last_header(array $headers) : string
    {
        for ($i = count($headers) - 1; $i >= 0; $i--)
        {
            if (trim($headers[$i]) !== '')
            {
                return $headers[$i];
            }
        }

        return '';
    }

    protected function fail_all(\Throwable $error) : void
    {
        foreach ($this->transfers as $id => $transfer)
        {
            curl_multi_remove_handle($this->multi(), $transfer->handle);
            curl_close($transfer->handle);
            $this->results[$id] = $error;
        }

        $this->transfers = [];
    }

    protected function store_result(Response|\Throwable $result) : int
    {
        $this->results[] = $result;

        return array_key_last($this->results);
    }

    protected function curl_headers(Request $request) : array
    {
        $headers = new Headers($this->headers);

        foreach ($request->headers as $header)
        {
            $headers->set($header);
        }

        return $headers->lines();
    }

    protected function curl_url(Request $request) : string
    {
        if ($request->host !== '')
        {
            return $request->url;
        }

        $host = $request->headers->value('host');

        if ($host === null)
        {
            throw new \InvalidArgumentException('CurlClient requires an absolute URL or a Host header.');
        }

        return "{$request->scheme}://{$host}{$request->target()}";
    }

    protected function curl_http_version(string $version) : int
    {
        return match ($version)
        {
            '1.0' => CURL_HTTP_VERSION_1_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2', '2.0' => CURL_HTTP_VERSION_2_0,
            '3', '3.0' => defined('CURL_HTTP_VERSION_3') ? CURL_HTTP_VERSION_3 : CURL_HTTP_VERSION_NONE,
            default => CURL_HTTP_VERSION_NONE,
        };
    }

    protected function curl_response_version(\CurlHandle $handle) : string
    {
        $version = curl_getinfo($handle, CURLINFO_HTTP_VERSION);

        if (defined('CURL_HTTP_VERSION_3') and $version === CURL_HTTP_VERSION_3)
        {
            return '3';
        }

        return match ($version)
        {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2',
            default => '1.1',
        };
    }

    protected function multi() : \CurlMultiHandle
    {
        if ($this->multi instanceof \CurlMultiHandle)
        {
            return $this->multi;
        }

        $multi = curl_multi_init();

        if (!$multi instanceof \CurlMultiHandle)
        {
            throw new \RuntimeException('Unable to create a curl multi handle.');
        }

        return $this->multi = $multi;
    }
}
