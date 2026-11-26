<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Contracts\EnforcementStore;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

final readonly class LaravelEnforcementStore implements EnforcementStore
{
    public function __construct(private Repository $cache, private string $scope) {}

    public function read(): array
    {
        if (! $this->cache->getStore() instanceof LockProvider) {
            throw new RuntimeException('Botect enforcement requires atomic cache locks.');
        }
        $state = $this->cache->get('botect:enforcement:v1:'.$this->scope, []);
        if (! is_array($state)) {
            throw new RuntimeException('Invalid Botect enforcement storage.');
        }

        return $state;
    }

    public function readCatalogue(string $id): ?array
    {
        $data = $this->cache->get('botect:enforcement:v1:'.$this->scope.':catalogue:'.$id);
        if ($data !== null && ! is_array($data)) {
            throw new RuntimeException('Invalid Botect catalogue.');
        }

        return $data;
    }

    public function writeCatalogue(string $id, array $catalogue): void
    {
        if (! $this->cache->put('botect:enforcement:v1:'.$this->scope.':catalogue:'.$id, $catalogue, 7200)) {
            throw new RuntimeException('Cannot publish Botect catalogue.');
        }
    }

    public function update(Closure $change): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Botect enforcement requires atomic cache locks.');
        }
        $key = 'botect:enforcement:v1:'.$this->scope;
        $lock = $store->lock($key.':lock', 5);
        if (! $lock->get()) {
            throw new RuntimeException('Botect enforcement storage is busy.');
        }
        try {
            $state = $this->cache->get($key, []);
            if (! is_array($state)) {
                throw new RuntimeException('Invalid Botect enforcement storage.');
            }
            $previous = $state;
            $result = $change($state);
            if ($state !== $previous && ! $this->cache->put($key, $state, 7200)) {
                throw new RuntimeException('Cannot publish Botect enforcement storage.');
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    public function persistent(): bool
    {
        return true;
    }
}
