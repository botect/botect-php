<?php

declare(strict_types=1);

namespace Botect\Laravel\ClientIp;

use Botect\Laravel\Contracts\ClientIpResolver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the resolver `botect.client_ip` names. The service provider binds
 * it, but the middleware and ingest controller also work when wired by hand
 * without the provider, so they fall back to this rather than to nothing.
 */
final class ClientIpResolverFactory
{
    public static function resolve(Container $app): ClientIpResolver
    {
        if ($app->bound(ClientIpResolver::class)) {
            return $app->make(ClientIpResolver::class);
        }

        return self::make($app);
    }

    public static function make(Container $app): ClientIpResolver
    {
        $config = $app->make('config');
        $setting = trim((string) ($config->get('botect.client_ip') ?? 'auto'));
        if ($setting === '' || $setting === 'auto') {
            try {
                $cache = $app->make('cache')->store($config->get('botect.cache_store'));
            } catch (Throwable) {
                $cache = null;
            }

            return new AutoIpResolver($app->make(LoggerInterface::class), $cache);
        }
        if ($setting === 'request') {
            return new RequestIpResolver;
        }
        if ($setting === 'cloudflare') {
            return new HeaderIpResolver('CF-Connecting-IP');
        }
        if (str_starts_with($setting, 'header:')) {
            return new HeaderIpResolver(substr($setting, 7));
        }
        if (class_exists($setting)) {
            $resolver = $app->make($setting);
            if ($resolver instanceof ClientIpResolver) {
                return $resolver;
            }
            throw new InvalidArgumentException('Botect client_ip class must implement '.ClientIpResolver::class.'.');
        }
        throw new InvalidArgumentException('Unknown Botect client_ip setting "'.$setting.'": use auto, request, cloudflare, header:<Name>, or a resolver class.');
    }
}
