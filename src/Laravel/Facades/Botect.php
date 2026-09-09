<?php

declare(strict_types=1);

namespace Botect\Laravel\Facades;

use Botect\Contracts\Dispatcher;
use Botect\Contracts\VerdictCache;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Botect\Verdict verdict(string $sessionToken, array $context = [])
 * @method static bool loggedIn(string $sessionToken)
 * @method static string collector(?\Botect\Page $page = null, ?string $cspNonce = null)
 * @method static ?string sessionToken(string $cookie)
 *
 * @see \Botect\Botect
 */
final class Botect extends Facade
{
    public static function fake(): FakeDispatcher
    {
        $fake = new FakeDispatcher;
        self::$app->instance(Dispatcher::class, $fake);
        self::$app->instance(VerdictCache::class, new ArrayVerdictCache);
        self::$app->forgetInstance(\Botect\Botect::class);
        self::clearResolvedInstance(\Botect\Botect::class);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Botect\Botect::class;
    }
}
