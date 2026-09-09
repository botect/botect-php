<?php

declare(strict_types=1);

namespace Botect\Contracts;

use Botect\Verdict;

interface VerdictCache
{
    public function get(string $key): ?Verdict;

    public function put(string $key, Verdict $verdict, int $ttl): void;

    public function generation(string $session): string;

    public function invalidate(string $session): void;
}
