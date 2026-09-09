<?php

declare(strict_types=1);

namespace Botect\Tests;

use Botect\Laravel\BotectServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BotectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('botect.site_key', 'pk_test');
        $app['config']->set('botect.private_key', 'sk_test_secret');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }
}
