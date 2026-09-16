<?php

declare(strict_types=1);

namespace Botect\Laravel\ClientIp;

use Botect\ClientIp;
use Botect\Laravel\Contracts\ClientIpResolver;
use Illuminate\Http\Request;

/**
 * Trusts `$request->ip()`, and therefore the application's own trusted-proxy
 * configuration. The right choice once that configuration is known to be
 * correct; behind an untrusted proxy it yields the proxy's private address,
 * which is dropped rather than sent.
 */
final class RequestIpResolver implements ClientIpResolver
{
    public function resolve(Request $request): ?ClientIp
    {
        return ClientIp::fromString($request->ip(), ClientIp::SOURCE_REQUEST);
    }
}
