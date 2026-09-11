<?php

declare(strict_types=1);

namespace Botect\Laravel\Http;

use Botect\Botect;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;

/**
 * The visitor's own signed session cookie, as the ingest endpoint sees it.
 *
 * The ingest route runs outside the web middleware group, so the cookie
 * arrives however the application's EncryptCookies left it: still encrypted
 * when that middleware is group-scoped, already plain when it runs globally,
 * and plain when the cookie is excepted or the application does not encrypt
 * cookies at all. Plain is tried first, then decryption with the same calls
 * EncryptCookies makes. Anything unverifiable reads as "no cookie", which the
 * caller treats as fail-open.
 */
final readonly class VisitorSessionCookie
{
    public function __construct(private Botect $botect, private Encrypter $encrypter) {}

    public function read(Request $request): ?string
    {
        $name = (string) config('botect.cookie_name');
        $raw = $request->cookie($name);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if ($this->botect->sessionToken($raw) !== null) {
            return $raw;
        }
        try {
            $value = $this->encrypter->decrypt($raw, EncryptCookies::serialized($name));
        } catch (DecryptException) {
            return null;
        }

        return is_string($value) ? CookieValuePrefix::validate($name, $value, $this->encrypter->getAllKeys()) : null;
    }
}
