<?php

declare(strict_types=1);

namespace Botect\Contracts;

use Botect\Http\Response;

interface HttpTransport
{
    /** @param array<string, string> $headers */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
