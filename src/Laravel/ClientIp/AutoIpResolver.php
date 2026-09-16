<?php

declare(strict_types=1);

namespace Botect\Laravel\ClientIp;

use Botect\ClientIp;
use Botect\Laravel\Contracts\ClientIpResolver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The default. Uses the request address when the application's proxy setup
 * yields a public one. When it yields a private address, the application sits
 * behind a proxy it does not trust, so the resolver looks for the headers the
 * common edges set and takes the first public address it finds, marking it
 * inferred. Botect weighs inferred addresses as weaker evidence, and the
 * operator is told, once, which setting would make them explicit.
 */
final class AutoIpResolver implements ClientIpResolver
{
    /** Edge-set headers, most specific first. A single-address header beats a chain. */
    public const HEADERS = ['CF-Connecting-IP', 'True-Client-IP', 'Fastly-Client-IP', 'Fly-Client-IP', 'X-Real-IP', 'X-Forwarded-For'];

    private const WARNED_KEY = 'botect:client-ip:inferred';

    private static bool $warned = false;

    public function __construct(private readonly LoggerInterface $logger, private readonly ?Cache $cache = null) {}

    public function resolve(Request $request): ?ClientIp
    {
        $direct = ClientIp::fromString($request->ip(), ClientIp::SOURCE_REQUEST);
        if ($direct !== null) {
            return $direct;
        }
        foreach (self::HEADERS as $header) {
            $ip = ClientIp::fromString(HeaderIpResolver::first($request->header($header)), ClientIp::SOURCE_HEADER, true);
            if ($ip !== null) {
                $this->warnOnce($header, (string) $request->ip());

                return $ip;
            }
        }

        return null;
    }

    /** Once per process, and once per day across processes when a cache is available. Never affects the response. */
    private function warnOnce(string $header, string $requestIp): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        try {
            if ($this->cache !== null && ! $this->cache->add(self::WARNED_KEY, true, 86400)) {
                return;
            }
        } catch (Throwable) {
            // A cache failure must not suppress the warning.
        }
        try {
            $this->logger->warning(
                'Botect is guessing the visitor IP from the '.$header.' header because the request address is private. Botect treats guessed addresses as weaker evidence. To make them explicit, set BOTECT_CLIENT_IP to the header your edge proxy overwrites (for example "cloudflare" or "header:'.$header.'"), or fix the application\'s trusted-proxy configuration and set BOTECT_CLIENT_IP=request.',
                ['request_ip' => $requestIp, 'header' => $header],
            );
        } catch (Throwable) {
            // Logging is best-effort.
        }
    }
}
