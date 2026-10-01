<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Laravel\Contracts\LoggedInResolver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class LoggedInResolverFactory
{
    public static function resolve(Container $app): ?LoggedInResolver
    {
        $config = $app->make('config');
        $botect = $config->get('botect', []);
        if (Arr::has($botect, 'logged_in.resolver')) {
            $setting = $config->get('botect.logged_in.resolver');
            if ($setting === null || $setting === '') {
                return null;
            }

            if (! is_string($setting) || ! class_exists($setting)) {
                throw new InvalidArgumentException('Botect logged_in.resolver must name a class implementing '.LoggedInResolver::class.'.');
            }
            $resolver = $app->make($setting);
            if (! $resolver instanceof LoggedInResolver) {
                throw new InvalidArgumentException('Botect logged_in.resolver class must implement '.LoggedInResolver::class.'.');
            }

            return $resolver;
        }

        if ($app->bound(LoggedInResolver::class)) {
            return $app->make(LoggedInResolver::class);
        }

        return $app->make(DefaultLoggedInResolver::class);
    }
}
