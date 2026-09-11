<?php

declare(strict_types=1);

namespace Botect\Tests;

use Psr\Log\AbstractLogger;
use Stringable;

final class FakeLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
