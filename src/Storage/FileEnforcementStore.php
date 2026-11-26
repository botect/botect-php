<?php

declare(strict_types=1);

namespace Botect\Storage;

use Botect\Contracts\EnforcementStore;
use Closure;
use RuntimeException;

final readonly class FileEnforcementStore implements EnforcementStore
{
    public function __construct(private string $directory) {}

    public function read(): array
    {
        $path = $this->directory.'/state.json';

        return is_file($path) ? Files::read($path) : [];
    }

    public function readCatalogue(string $id): ?array
    {
        $path = $this->cataloguePath($id);
        if (! is_file($path)) {
            return null;
        }
        $body = @file_get_contents($path);
        // Packed intervals are base64 encoded for private JSON storage.
        if ($body === false || strlen($body) > 8388608) {
            throw new RuntimeException('Cannot read Botect catalogue.');
        }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid Botect catalogue.');
        }

        return $data;
    }

    public function writeCatalogue(string $id, array $catalogue): void
    {
        Files::directory($this->directory);
        Files::write($this->cataloguePath($id), $catalogue);
        // Retain immutable artifacts beyond every snapshot's maximum lifetime.
        foreach (glob($this->directory.'/catalogue-*.json') ?: [] as $path) {
            if (@filemtime($path) < time() - 7200) {
                @unlink($path);
            }
        }
    }

    private function cataloguePath(string $id): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new RuntimeException('Invalid Botect catalogue identifier.');
        }

        return $this->directory.'/catalogue-'.$id.'.json';
    }

    public function update(Closure $change): mixed
    {
        Files::directory($this->directory);
        $lock = @fopen($this->directory.'/state.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot lock Botect enforcement storage.');
        }
        try {
            @chmod($this->directory.'/state.lock', 0600);
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Botect enforcement storage is busy.');
            }
            $path = $this->directory.'/state.json';
            $state = is_file($path) ? Files::read($path) : [];
            $previous = $state;
            $result = $change($state);
            if ($state !== $previous) {
                Files::write($path, $state);
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function persistent(): bool
    {
        return true;
    }
}
