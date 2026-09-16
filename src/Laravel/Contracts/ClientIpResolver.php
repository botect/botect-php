<?php

declare(strict_types=1);

namespace Botect\Laravel\Contracts;

use Botect\ClientIp;
use Illuminate\Http\Request;

/**
 * Decides which address to report as the visitor's for a request.
 *
 * Bind your own implementation, or name its class in `botect.client_ip`, when
 * your application already knows how to find the real client address, for
 * example from a header your edge proxy is known to overwrite. Return null
 * when no trustworthy public address is available; the SDK then sends none.
 */
interface ClientIpResolver
{
    public function resolve(Request $request): ?ClientIp;
}
