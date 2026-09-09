<?php

declare(strict_types=1);

namespace Botect\Testing;

use Botect\Contracts\VerdictCache;
use Botect\Verdict;

final class ArrayVerdictCache implements VerdictCache
{
    /** @var array<string, array{verdict:Verdict, expires:int}> */
    private array $values = [];

    /** @var array<string, string> */
    private array $generations = [];

    public function get(string $key): ?Verdict
    {
        $entry = $this->values[$key] ?? null;

        return $entry !== null && $entry['expires'] > time() ? $entry['verdict'] : null;
    }

    public function put(string $key, Verdict $verdict, int $ttl): void
    {
        $this->values[$key] = ['verdict' => $verdict, 'expires' => time() + $ttl];
    }

    public function generation(string $session): string
    {
        return $this->generations[$session] ?? '0';
    }

    public function invalidate(string $session): void
    {
        $this->generations[$session] = bin2hex(random_bytes(16));
    }
}
