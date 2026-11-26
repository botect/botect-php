<?php

declare(strict_types=1);

namespace Botect\Storage;

use Botect\Contracts\EnforcementStore;
use Closure;

final class MemoryEnforcementStore implements EnforcementStore
{
    private array $state = [];

    private array $catalogues = [];

    public function read(): array
    {
        return $this->state;
    }

    public function readCatalogue(string $id): ?array
    {
        return $this->catalogues[$id] ?? null;
    }

    public function writeCatalogue(string $id, array $catalogue): void
    {
        $this->catalogues[$id] = $catalogue;
    }

    public function update(Closure $change): mixed
    {
        return $change($this->state);
    }

    public function persistent(): bool
    {
        return false;
    }
}
