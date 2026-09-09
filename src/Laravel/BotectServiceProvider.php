<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\HttpTransport;
use Botect\Contracts\VerdictCache;
use Botect\Laravel\Commands\FlushCommand;
use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Laravel\Http\IngestController;
use Botect\Laravel\Middleware\EnforceVerdict;
use Botect\Laravel\Middleware\TrackPage;
use Botect\Storage\FileSpool;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class BotectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/botect.php', 'botect');
        $this->app->singleton(Configuration::class, fn ($app): Configuration => new Configuration(
            siteKey: (string) $app['config']->get('botect.site_key'),
            privateKey: $app['config']->get('botect.private_key'),
            apiUrl: $app['config']->get('botect.api_url'),
            collectorUrl: $app['config']->get('botect.collector_url'),
            serverIngestEnabled: (bool) $app['config']->get('botect.server_ingest_enabled'),
            ingestPath: $app['config']->get('botect.ingest_path'),
            connectTimeoutMs: (int) $app['config']->get('botect.connect_timeout_ms'),
            timeoutMs: (int) $app['config']->get('botect.timeout_ms'),
            verdictTtl: (int) $app['config']->get('botect.verdict_ttl'),
            pageTokenTtl: (int) $app['config']->get('botect.page_token_ttl'),
            maxBodyBytes: (int) $app['config']->get('botect.max_body_bytes'),
        ));
        $this->app->singletonIf(HttpTransport::class, fn ($app): HttpTransport => new LaravelHttpTransport($app->make(Factory::class), $app->make(Configuration::class)));
        $this->app->singletonIf(VerdictCache::class, fn ($app): VerdictCache => new LaravelVerdictCache($app['cache']->store($app['config']->get('botect.cache_store')), $app->make(Configuration::class)->namespace()));
        $this->app->singletonIf(Dispatcher::class, function ($app): Dispatcher {
            return match ($app['config']->get('botect.delivery')) {
                'queue' => new QueueDispatcher($app->make(Bus::class), $app['config'], $app->make(LoggerInterface::class), $app['cache']->store()),
                'spool' => new FileSpool(rtrim($app['config']->get('botect.storage_path'), '/').'/'.$app->make(Configuration::class)->namespace().'/spool', (int) $app['config']->get('botect.spool_capacity')),
                default => throw new InvalidArgumentException('Unknown Botect delivery driver.'),
            };
        });
        $this->app->singletonIf(VerdictHandler::class, fn ($app): VerdictHandler => $app->make($app['config']->get('botect.enforcement.handler')));
        $this->app->singleton(Botect::class, fn ($app): Botect => new Botect($app->make(Configuration::class), $app->make(Dispatcher::class), $app->make(VerdictCache::class), $app->make(HttpTransport::class)));
    }

    public function boot(Router $router): void
    {
        $this->publishes([__DIR__.'/../../config/botect.php' => config_path('botect.php')], 'botect-config');
        $router->aliasMiddleware('botect.track', TrackPage::class);
        $router->aliasMiddleware('botect.enforce', EnforceVerdict::class);
        if (config('botect.tracking.enabled') && config('botect.server_ingest_enabled')) {
            $router->pushMiddlewareToGroup('web', TrackPage::class);
        }
        if (! $this->app->routesAreCached() && config('botect.server_ingest_enabled') && config('botect.site_key')) {
            $path = $this->app->make(Configuration::class)->ingestPath;
            $router->post($path, IngestController::class)->name('botect.ingest');
        }
        Blade::directive('botect', static function (string $expression): string {
            $nonce = trim($expression) === '' ? 'null' : $expression;

            return '<?php echo config("botect.site_key") ? app(\\Botect\\Botect::class)->collector(request()->attributes->get("botect.page"), '.$nonce.') : ""; ?>';
        });
        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class]);
        }
    }
}
