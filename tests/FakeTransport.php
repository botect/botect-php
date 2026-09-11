<?php

declare(strict_types=1);

namespace Botect\Tests;

use Botect\Contracts\TimeoutAwareTransport;
use Botect\Http\Response;
use RuntimeException;
use Throwable;

final class FakeTransport implements TimeoutAwareTransport
{
    /** @var list<array{0: int, 1: int}> every withTimeouts() call, in order */
    public array $timeoutCalls = [];

    /** @var list<array{method:string, url:string, headers:array<string,string>, body:?string}> */
    public array $requests = [];

    public Response|Throwable $result;

    public function __construct()
    {
        $this->result = new RuntimeException('Unexpected network request.');
    }

    /** Records the limits and keeps recording requests on the same instance. */
    public function withTimeouts(int $connectTimeoutMs, int $timeoutMs): static
    {
        $this->timeoutCalls[] = [$connectTimeoutMs, $timeoutMs];

        return $this;
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
