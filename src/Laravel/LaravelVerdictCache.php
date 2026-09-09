<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Contracts\VerdictCache;
use Botect\Verdict;
use Illuminate\Contracts\Cache\Repository;

final readonly class LaravelVerdictCache implements VerdictCache
{
    public function __construct(private Repository $cache, private string $namespace) {}

    public function get(string $key): ?Verdict
    {
        $data = $this->cache->get($this->key($key));

        return is_array($data) ? Verdict::fromArray($data) : null;
    }

    public function put(string $key, Verdict $verdict, int $ttl): void
    {
        $this->cache->put($this->key($key), $verdict->toArray(), $ttl);
    }

    public function generation(string $session): string
    {
        return (string) $this->cache->get($this->key('generation:'.$session), '0');
    }

    public function invalidate(string $session): void
    {
        $this->cache->put($this->key('generation:'.$session), bin2hex(random_bytes(16)), 7200);
    }

    private function key(string $key): string
    {
        return 'botect:'.$this->namespace.':'.hash('sha256', $key);
    }
}
