<?php

declare(strict_types=1);

namespace Botect\Storage;

use RuntimeException;

final class Files
{
    public static function directory(string $directory): void
    {
        if (file_exists($directory) && ! is_dir($directory)) {
            throw new RuntimeException('Botect storage path is not a directory.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create Botect storage.');
        }
        if (is_link($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Botect storage must be a writable private directory.');
        }
        @chmod($directory, 0700);
    }

    /** @param array<string, mixed> $data */
    public static function write(string $path, array $data): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Cannot write Botect storage.');
            }
            @chmod($temporary, 0600);
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot finalize Botect storage.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function read(string $path): array
    {
        $body = @file_get_contents($path);
        if ($body === false || strlen($body) > 1048576) {
            throw new RuntimeException('Cannot read Botect storage.');
        }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Invalid Botect storage.');
        }

        return $data;
    }
}
