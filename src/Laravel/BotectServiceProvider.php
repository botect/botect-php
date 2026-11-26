<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Actions\DeliverAction;
use Botect\ApiClient;
use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\ChallengeRenderer;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\EnforcementStore;
use Botect\Contracts\HttpTransport;
use Botect\Contracts\VerdictCache;
use Botect\Delivery\DeferredDispatcher;
use Botect\Laravel\ClientIp\ClientIpResolverFactory;
use Botect\Laravel\Commands\FlushCommand;
use Botect\Laravel\Commands\RefreshChallengeListCommand;
use Botect\Laravel\Contracts\ClientIpResolver;
use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Laravel\Http\ChallengeController;
use Botect\Laravel\Http\IngestController;
use Botect\Laravel\Middleware\EnforceVerdict;
use Botect\Laravel\Middleware\TrackPage;
use Botect\Storage\FileSpool;
use Botect\Support\ChallengePage;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
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
            deliveryConnectTimeoutMs: (int) $app['config']->get('botect.delivery_connect_timeout_ms', 1000),
            deliveryTimeoutMs: (int) $app['config']->get('botect.delivery_timeout_ms', 5000),
            localChallengesEnabled: (bool) $app['config']->get('botect.enforcement.local_challenges.enabled', false),
            localChallengeAction: $app['config']->get('botect.enforcement.local_challenges.action', 'challenge'),
            challengePath: $app['config']->get('botect.enforcement.local_challenges.path', '/_botect/challenge'),
            clearanceSeconds: (int) $app['config']->get('botect.enforcement.local_challenges.clearance_seconds', 1800),
        ));
        $this->app->singletonIf(HttpTransport::class, fn ($app): HttpTransport => new LaravelHttpTransport($app->make(Factory::class), $app->make(Configuration::class)));
        $this->app->singletonIf(VerdictCache::class, fn ($app): VerdictCache => new LaravelVerdictCache($app['cache']->store($app['config']->get('botect.cache_store')), $app->make(Configuration::class)->namespace()));
        $this->app->singletonIf(EnforcementStore::class, fn ($app) => new LaravelEnforcementStore($app['cache']->store($app['config']->get('botect.cache_store')), $app->make(Configuration::class)->enforcementScope()));
        $this->app->singletonIf(ChallengeRenderer::class, fn ($app) => $app->make($app['config']->get('botect.enforcement.local_challenges.renderer', ChallengePage::class)));
        $this->app->scopedIf(Dispatcher::class, function ($app): Dispatcher {
            return match ($app['config']->get('botect.delivery')) {
                'deferred' => new DeferredDispatcher((new DeliverAction(
                    new ApiClient($app->make(Configuration::class), $app->make(HttpTransport::class)),
                    $app->make(VerdictCache::class),
                    $app->make(Configuration::class),
                    $app->make(EnforcementStore::class),
                ))->execute(...)),
                'queue' => new QueueDispatcher($app->make(Bus::class), $app['config'], $app->make(LoggerInterface::class), $app['cache']->store()),
                'spool' => new FileSpool(rtrim($app['config']->get('botect.storage_path'), '/').'/'.$app->make(Configuration::class)->namespace().'/spool', (int) $app['config']->get('botect.spool_capacity')),
                default => throw new InvalidArgumentException('Unknown Botect delivery driver.'),
            };
        });
        $this->app->singletonIf(ClientIpResolver::class, fn ($app): ClientIpResolver => ClientIpResolverFactory::make($app));
        $this->app->singletonIf(VerdictHandler::class, fn ($app): VerdictHandler => $app->make($app['config']->get('botect.enforcement.handler')));
        $this->app->scoped(Botect::class, fn ($app): Botect => new Botect($app->make(Configuration::class), $app->make(Dispatcher::class), $app->make(VerdictCache::class), $app->make(HttpTransport::class), $app->make(EnforcementStore::class), static function (string $event, array $context) use ($app): void {
            $app->make(LoggerInterface::class)->info($event, $context);
        }));
    }

    public function boot(Router $router): void
    {
        $this->app->terminating(static function (Application $app): void {
            if ($app->resolved(Dispatcher::class)) {
                $dispatcher = $app->make(Dispatcher::class);
                if ($dispatcher instanceof DeferredDispatcher) {
                    $dispatcher->drain();
                }
            }
        });
        $this->publishes([__DIR__.'/../../config/botect.php' => config_path('botect.php')], 'botect-config');
        $router->aliasMiddleware('botect.track', TrackPage::class);
        $router->aliasMiddleware('botect.enforce', EnforceVerdict::class);
        if (config('botect.tracking.enabled') && config('botect.server_ingest_enabled')) {
            // Published configurations from before `scope` existed replace the
            // whole `tracking` array, so an absent key means the old behaviour.
            match (config('botect.tracking.scope', 'web')) {
                'web' => $this->trackWebRoutes($router),
                'manual' => null,
                default => throw new InvalidArgumentException('Unknown Botect tracking scope.'),
            };
        }
        if (! $this->app->routesAreCached() && config('botect.server_ingest_enabled') && config('botect.site_key')) {
            $path = $this->app->make(Configuration::class)->ingestPath;
            $router->post($path, IngestController::class)->name('botect.ingest');
        }
        // Resolve configuration at boot so invalid local actions cannot be swallowed by middleware.
        if (! in_array(config('botect.enforcement.local_challenges.action', 'challenge'), ['challenge', 'block'], true)) {
            throw new InvalidArgumentException('Invalid local challenge action.');
        }
        if (config('botect.enforcement.local_challenges.enabled')) {
            EncryptCookies::except(['botect_challenge_pass']);
        }
        if (! $this->app->routesAreCached() && config('botect.enforcement.local_challenges.enabled') && config('botect.server_ingest_enabled') && config('botect.site_key')) {
            $router->post($this->app->make(Configuration::class)->challengePath, ChallengeController::class)->name('botect.challenge');
        }
        if (config('botect.server_ingest_enabled')) {
            // A bad client_ip value fails here, at boot, rather than turning
            // every tracked page and ingest request into a swallowed error.
            $this->app->make(ClientIpResolver::class);
        }
        if ((config('botect.server_ingest_enabled') && config('botect.tracking.enabled')) || config('botect.enforcement.enabled')) {
            LoggedInResolverFactory::resolve($this->app);
        }
        Blade::directive('botect', static function (string $expression): string {
            $nonce = trim($expression) === '' ? 'null' : $expression;

            return '<?php echo config("botect.site_key") ? app(\\Botect\\Botect::class)->collector(request()->attributes->get("botect.page"), '.$nonce.') : ""; ?>';
        });
        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class, RefreshChallengeListCommand::class]);
        }
    }

    /**
     * Add tracking to the Kernel's web group rather than only the Router's.
     *
     * The HTTP Kernel owns middleware groups, and its group and priority
     * mutators copy them back over the Router's. Laravel Sanctum calls one
     * during its own boot, after this provider in discovery order, so a
     * Router-only push was silently discarded: no collector, no session
     * cookie, no page observations, and no error.
     */
    private function trackWebRoutes(Router $router): void
    {
        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);
            if ($kernel instanceof FoundationKernel && array_key_exists('web', $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup('web', TrackPage::class);

                return;
            }
        }
        $router->pushMiddlewareToGroup('web', TrackPage::class);
    }
}
