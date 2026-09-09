<?php

declare(strict_types=1);

namespace Botect\Storage;

use Botect\Contracts\VerdictCache;
use Botect\Verdict;
use Throwable;

final readonly class FileVerdictCache implements VerdictCache
{
    public function __construct(private string $directory) {}

    public function get(string $key): ?Verdict
    {
        $path = $this->path('verdict:'.$key);
        if (! is_file($path)) {
            return null;
        }
        try {
            $data = Files::read($path);
            if ($data['expires_at'] <= time()) {
                @unlink($path);

                return null;
            }

            return Verdict::fromArray($data['verdict']);
        } catch (Throwable) {
            return null;
        }
    }

    public function put(string $key, Verdict $verdict, int $ttl): void
    {
        Files::directory($this->directory);
        Files::write($this->path('verdict:'.$key), ['expires_at' => time() + $ttl, 'verdict' => $verdict->toArray()]);
    }

    public function generation(string $session): string
    {
        $path = $this->path('session:'.$session);

        if (! is_file($path)) {
            return '0';
        }
        $data = Files::read($path);

        return ($data['expires_at'] ?? 0) > time() ? (string) $data['generation'] : '0';
    }

    public function invalidate(string $session): void
    {
        Files::directory($this->directory);
        Files::write($this->path('session:'.$session), ['generation' => bin2hex(random_bytes(16)), 'expires_at' => time() + 7200]);
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.hash('sha256', $key).'.json';
    }

    public function prune(): int
    {
        $removed = 0;
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            try {
                $data = Files::read($path);
                if (isset($data['expires_at']) && $data['expires_at'] <= time() && @unlink($path)) {
                    $removed++;
                }
            } catch (Throwable) {
                if (@unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
