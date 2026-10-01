<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

final class LoggedInState
{
    public static function of(Container $app, Request $request): ?bool
    {
        try {
            return LoggedInResolverFactory::resolve($app)?->loggedIn($request);
        } catch (Throwable) {
            return null;
        }
    }
}
