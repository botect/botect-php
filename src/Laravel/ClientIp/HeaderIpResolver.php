<?php

declare(strict_types=1);

namespace Botect\Laravel\ClientIp;

use Botect\ClientIp;
use Botect\Laravel\Contracts\ClientIpResolver;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Reads one named header, such as `CF-Connecting-IP`. Only safe when the
 * proxy in front of the application overwrites that header on every request;
 * a header the client can set itself is a forged address waiting to happen.
 * The operator chose the header, so the result counts as explicit evidence.
 */
final class HeaderIpResolver implements ClientIpResolver
{
    public function __construct(public readonly string $header, private readonly bool $inferred = false)
    {
        if (! preg_match('/^[A-Za-z0-9-]{1,64}$/D', $header)) {
            throw new InvalidArgumentException('Invalid client IP header name.');
        }
    }

    public function resolve(Request $request): ?ClientIp
    {
        return ClientIp::fromString(self::first($request->header($this->header)), ClientIp::SOURCE_HEADER, $this->inferred);
    }

    /** Some headers carry a proxy chain; the first entry is the original client. */
    public static function first(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $first = explode(',', $value, 2)[0];

        return trim($first) === '' ? null : trim($first);
    }
}
