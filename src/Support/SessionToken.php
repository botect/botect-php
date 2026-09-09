<?php

declare(strict_types=1);

namespace Botect\Support;

use InvalidArgumentException;

final class SessionToken
{
    public static function validate(string $token): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $token)) {
            throw new InvalidArgumentException('Invalid Botect session token.');
        }

        return $token;
    }

    public static function generate(): string
    {
        return 'sess_'.bin2hex(random_bytes(24));
    }
}
