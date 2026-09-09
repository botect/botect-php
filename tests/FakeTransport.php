<?php

declare(strict_types=1);

namespace Botect\Tests;

use Botect\Contracts\HttpTransport;
use Botect\Http\Response;
use RuntimeException;
use Throwable;

final class FakeTransport implements HttpTransport
{
    /** @var list<array{method:string, url:string, headers:array<string,string>, body:?string}> */
    public array $requests = [];

    public Response|Throwable $result;

    public function __construct()
    {
        $this->result = new RuntimeException('Unexpected network request.');
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
