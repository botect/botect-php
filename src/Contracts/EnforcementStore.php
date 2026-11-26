<?php

declare(strict_types=1);

namespace Botect\Contracts;

use Closure;

/** One credential scope. Lock contention and storage failures throw; callers allow. */
interface EnforcementStore
{
    /** @return array<string, mixed> */
    public function read(): array;

    /** Immutable catalogue artifacts are fetched only after a shape hit or during refresh. */
    public function readCatalogue(string $id): ?array;

    public function writeCatalogue(string $id, array $catalogue): void;

    /** Nonblocking atomic read/modify/write of snapshot, catalogue, lease and backoff.
     * @param  Closure(array<string, mixed>&): mixed  $change
     */
    public function update(Closure $change): mixed;

    public function persistent(): bool;
}
