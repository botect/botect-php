<?php

declare(strict_types=1);
use Botect\ApiClient;
use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\HttpTransport;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Botect\Laravel\BotectServiceProvider;
use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Laravel\Http\IngestController;
use Botect\Laravel\Jobs\DeliverJob;
use Botect\Laravel\LaravelHttpTransport;
use Botect\Laravel\Middleware\TrackPage;
use Botect\Laravel\QueueDispatcher;
use Botect\Operation;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeLogger;
use Botect\Tests\FakeTransport;
use Botect\Verdict;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

beforeEach(function (): void {
    $this->dispatcher = new FakeDispatcher;
    $this->transport = new FakeTransport;
    $this->app->instance(Dispatcher::class, $this->dispatcher);
    $this->app->instance(HttpTransport::class, $this->transport);
});

test('provider resolves shared SDK and Blade uses the real collector contract', function (): void {
    expect($this->app->make(Botect::class))->toBeInstanceOf(Botect::class)
        ->and(Blade::render('@botect("csp-value")'))->toContain('data-site-key="pk_test"', 'nonce="csp-value"', 'https://api.botect.ai/v1/events')
        ->and($this->transport->requests)->toBe([]);
    config(['botect.site_key' => '']);
    expect(Blade::render('@botect'))->toBe('');
});

test('tracking emits a signed HttpOnly cookie and an asynchronous sanitized page hit', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    Route::get('/members/{email}', fn () => response('<html><head></head><body>ok</body></html>', 200, ['Content-Type' => 'text/html', 'ETag' => 'old', 'Content-Length' => '42']))->middleware('botect.track');
    $response = $this->get('/members/alice@example.com?secret=x');
    $response->assertOk()->assertSee('data-botect-sdk', false)->assertHeaderMissing('ETag')->assertHeaderMissing('Content-Length');
    $cookies = $response->headers->getCookies();
    expect($cookies)->toHaveCount(1)->and($cookies[0]->isHttpOnly())->toBeTrue()
        ->and($cookies[0]->getSameSite())->toBe('lax')->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($this->dispatcher->forOperation(Operation::RecordPage))->toHaveCount(1)
        ->and(json_encode($this->dispatcher->deliveries[0]->payload))->not->toContain('alice', 'secret')
        ->and($this->transport->requests)->toBe([]);
});

test('tracking skips JSON, HEAD, exclusions and disabled configurations', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true, 'botect.tracking.except' => ['excluded']]);
    Route::get('/json', fn () => response()->json(['ok' => true]))->middleware('botect.track');
    Route::get('/html', fn () => response('<html><head></head></html>'))->middleware('botect.track');
    Route::get('/excluded', fn () => response('<html><head></head></html>'))->middleware('botect.track');
    $this->get('/json')->assertOk();
    $this->head('/html')->assertOk();
    $this->get('/excluded')->assertDontSee('data-botect-sdk', false);
    config(['botect.server_ingest_enabled' => false]);
    $this->get('/html')->assertDontSee('data-botect-sdk', false);
    expect($this->dispatcher->deliveries)->toBe([]);
});

test('tracking does not duplicate a manually rendered collector', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    Route::get('/page', fn () => response(Blade::render('<html><head>@botect</head></html>')))->middleware('botect.track');
    $response = $this->get('/page');
    expect(substr_count($response->getContent(), 'data-botect-sdk'))->toBe(1);
});

test('SDK storage failures never swallow host application exceptions', function (): void {
    $this->withoutExceptionHandling();
    config(['botect.site_key' => '', 'botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    Route::get('/broken', fn () => throw new RuntimeException('Host application failed'))->middleware('botect.track');
    expect(fn () => $this->get('/broken'))->toThrow(RuntimeException::class, 'Host application failed');
});

test('proxy validates tokens and JSON before queuing and rejects foreign origins', function (): void {
    config(['botect.server_ingest_enabled' => true]);
    Route::post('/collect', IngestController::class);
    $page = $this->app->make(Botect::class)->page();
    $body = ['events' => [['request_id' => 'event_1', 'type' => 'js_probe', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => ['js_passed' => true]]]];
    $this->postJson('/collect?page_token='.urlencode($page->token), $body)->assertAccepted()->assertJson(['queued' => true]);
    $this->postJson('/collect?page_token=bad', $body)->assertUnprocessable();
    $this->postJson('/collect?page_token='.urlencode($page->token), $body, ['Origin' => 'https://evil.example'])->assertForbidden();
    $this->postJson('/collect?page_token='.urlencode($page->token), ['secret' => 'value'])->assertUnprocessable();
    expect($this->dispatcher->deliveries)->toHaveCount(1)->and($this->transport->requests)->toBe([]);
});

test('proxy rejects a cached page token sent with another visitor\'s cookie and warns once per page', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    $this->app->instance(LoggerInterface::class, $logger = new FakeLogger);
    Route::get('/cookie-page', fn () => response('<html><head></head><body>ok</body></html>'))->middleware(['web', 'botect.track']);
    Route::post('/collect', IngestController::class);
    $body = ['events' => [['request_id' => 'e1', 'type' => 'js_probe', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => ['js_passed' => true]]]];
    // A tracked visit as seen by the browser: the token in the page and the encrypted cookie.
    $visit = function (?string $cookie = null): array {
        $this->unencryptedCookies = $cookie === null ? [] : ['botect_server_session' => $cookie];
        $response = $this->get('/cookie-page')->assertOk();
        preg_match('/page_token=([^"&]+)/', $response->getContent(), $match);
        $jar = collect($response->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'botect_server_session');

        return ['token' => urldecode($match[1]), 'cookie' => $jar->getValue()];
    };
    $post = function (string $token, ?string $cookie, array $headers = []) use ($body) {
        $this->unencryptedCookies = $cookie === null ? [] : ['botect_server_session' => $cookie];

        // JSON test requests carry no cookies unless credentials are opted in.
        return $this->withCredentials()->postJson('/collect?page_token='.urlencode($token), $body, $headers);
    };
    $a = $visit();
    $b = $visit();

    // The cached-page shape: A's token in the page, B's cookie in the browser.
    $rejected = $post($a['token'], $b['cookie'], ['Referer' => 'http://localhost/cookie-page?secret=x'])
        ->assertStatus(409)->assertJson(['error' => 'Page token belongs to another session']);
    expect($rejected->headers->get('Cache-Control'))->toContain('no-store')
        ->and($this->dispatcher->forOperation(Operation::ForwardEvents))->toBe([])
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0][0])->toBe('warning')
        ->and($logger->records[0][2]['page_id'])->toMatch('/^[a-f0-9]{32}$/')
        ->and($logger->records[0][2]['path'])->toBe('/cookie-page')
        ->and(json_encode($logger->records[0]))->not->toContain('sess_', 'secret');
    $post($a['token'], $b['cookie'])->assertStatus(409);
    expect($logger->records)->toHaveCount(1);

    // The visitor the token was minted for is still accepted.
    $post($a['token'], $a['cookie'])->assertAccepted();
    $forwarded = $this->dispatcher->forOperation(Operation::ForwardEvents);
    expect($forwarded)->toHaveCount(1)
        ->and($forwarded[0]->sessionToken)->toBe($this->dispatcher->forOperation(Operation::RecordPage)[0]->payload['session_token']);

    // A new token for the same session is a new page: the throttle is per token.
    $again = $visit($a['cookie']);
    $post($again['token'], $b['cookie'])->assertStatus(409);
    expect($logger->records)->toHaveCount(2);
});

test('proxy fails open on absent, tampered, or undecryptable cookies', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    $this->app->instance(LoggerInterface::class, $logger = new FakeLogger);
    Route::get('/cookie-page', fn () => response('<html><head></head><body>ok</body></html>'))->middleware(['web', 'botect.track']);
    Route::post('/collect', IngestController::class);
    $body = ['events' => [['request_id' => 'e1', 'type' => 'js_probe', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => ['js_passed' => true]]]];
    $page = $this->get('/cookie-page')->assertOk();
    preg_match('/page_token=([^"&]+)/', $page->getContent(), $match);
    $token = urldecode($match[1]);
    $other = collect($this->get('/cookie-page')->headers->getCookies())->first(fn ($c): bool => $c->getName() === 'botect_server_session')->getValue();
    $encrypter = $this->app->make(Encrypter::class);
    $plainOther = CookieValuePrefix::remove($encrypter->decrypt($other, false));
    // A real cookie value encrypted for a DIFFERENT cookie name fails the prefix check.
    $wrongName = $encrypter->encrypt(CookieValuePrefix::create('other', $encrypter->getKey()).$plainOther, false);
    // Flip a byte inside the ciphertext; appending past the base64 padding would decode unchanged.
    $tampered = substr_replace($other, $other[20] === 'x' ? 'y' : 'x', 20, 1);
    foreach ([null, 'garbage', $tampered, $wrongName] as $cookie) {
        $this->unencryptedCookies = $cookie === null ? [] : ['botect_server_session' => $cookie];
        $this->withCredentials()->postJson('/collect?page_token='.urlencode($token), $body)->assertAccepted();
    }
    expect($this->dispatcher->forOperation(Operation::ForwardEvents))->toHaveCount(4)->and($logger->records)->toBe([]);
});

test('proxy compares a plain signed cookie when the application does not encrypt it', function (): void {
    // Global EncryptCookies, an excepted cookie, or no cookie encryption at all
    // hand the endpoint the plain signed value.
    config(['botect.server_ingest_enabled' => true]);
    $this->app->instance(LoggerInterface::class, $logger = new FakeLogger);
    Route::post('/collect', IngestController::class);
    $sdk = $this->app->make(Botect::class);
    $a = $sdk->page();
    $b = $sdk->page();
    $body = ['events' => [['request_id' => 'e1', 'type' => 'js_probe', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => ['js_passed' => true]]]];
    $this->withCredentials()->withUnencryptedCookie('botect_server_session', $sdk->sessionCookie($b))->postJson('/collect?page_token='.urlencode($a->token), $body)->assertStatus(409);
    $this->withCredentials()->withUnencryptedCookie('botect_server_session', $sdk->sessionCookie($a))->postJson('/collect?page_token='.urlencode($a->token), $body)->assertAccepted();
    expect($this->dispatcher->forOperation(Operation::ForwardEvents))->toHaveCount(1)->and($logger->records)->toHaveCount(1);
});

test('proxy returns unavailable rather than claiming an unqueued batch succeeded', function (): void {
    config(['botect.server_ingest_enabled' => true]);
    $this->dispatcher->accept = false;
    Route::post('/collect', IngestController::class);
    $page = $this->app->make(Botect::class)->page();
    $this->postJson('/collect?page_token='.urlencode($page->token), ['events' => [['request_id' => 'e1', 'type' => 'page', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => []]]])->assertServiceUnavailable()->assertJson(['queued' => false]);
});

test('queue adapter refuses sync execution and dispatches real async jobs', function (): void {
    Bus::fake();
    $dispatcher = $this->app->make(QueueDispatcher::class);
    $delivery = Delivery::make(Operation::AssertLoggedIn, 'sess_test');
    expect($dispatcher->dispatch($delivery))->toBeFalse();
    Bus::assertNothingDispatched();
    config(['botect.queue_connection' => 'redis', 'queue.connections.redis.driver' => 'redis']);
    expect($dispatcher->dispatch($delivery))->toBeTrue();
    Bus::assertDispatched(DeliverJob::class, fn (DeliverJob $job): bool => $job->queue === null && $job->connection === 'redis');
});

test('enforcement fails open on cache misses and enforces cached verdicts only', function (): void {
    config(['botect.enforcement.enabled' => true]);
    Route::get('/checkout', fn () => 'ok')->middleware('botect.enforce');
    $sdk = $this->app->make(Botect::class);
    $page = $sdk->page();
    $cookie = $sdk->sessionCookie($page);
    $this->withUnencryptedCookie('botect_server_session', $cookie)->get('/checkout')->assertOk();
    expect($this->dispatcher->deliveries)->toHaveCount(1);
    $this->transport->result = new Response(200, '{"verdict":"definite","score":1,"action":"block","detection_ids":[],"reason":"automation"}');
    $sdk->deliver($this->dispatcher->deliveries[0]);
    $this->withUnencryptedCookie('botect_server_session', $cookie)->get('/checkout')->assertForbidden();
    expect($this->transport->requests)->toHaveCount(1);
});

test('custom challenge handler can connect a customer challenge flow', function (): void {
    config(['botect.enforcement.enabled' => true]);
    $this->app->instance(VerdictHandler::class, new class implements VerdictHandler
    {
        public function handle(Request $request, Verdict $verdict): ?HttpResponse
        {
            return $verdict->action === 'challenge' ? redirect('/challenge') : null;
        }
    });
    Route::get('/checkout', fn () => 'ok')->middleware('botect.enforce');
    $sdk = $this->app->make(Botect::class);
    $page = $sdk->page();
    $sdk->verdict($page->sessionToken, ['path' => '/checkout']);
    $this->transport->result = new Response(200, '{"verdict":"likely_automated","score":10,"action":"challenge","detection_ids":[],"reason":"challenge"}');
    $sdk->deliver($this->dispatcher->deliveries[0]);
    $this->withUnencryptedCookie('botect_server_session', $sdk->sessionCookie($page))->get('/checkout')->assertRedirect('/challenge');
});

test('job serialization contains no credentials and handles the login mint race', function (): void {
    $sdk = $this->app->make(Botect::class);
    $sdk->loggedIn('sess_test');
    $job = new DeliverJob($this->dispatcher->deliveries[0]);
    expect(serialize($job))->not->toContain('sk_test_secret', 'pk_test');
    $this->transport->result = new Response(404, '{"code":"UNKNOWN_SESSION"}');
    expect(fn () => $job->handle($sdk))->toThrow(DeliveryException::class);
});

test('facade fake captures deliveries without real network or queue work', function (): void {
    $fake = \Botect\Laravel\Facades\Botect::fake();
    expect(\Botect\Laravel\Facades\Botect::loggedIn('sess_test'))->toBeTrue()
        ->and($fake->forOperation(Operation::AssertLoggedIn))->toHaveCount(1)
        ->and($this->transport->requests)->toBe([]);
});

test('Laravel transport respects Http fakes and keeps login bodies empty', function (): void {
    Http::preventStrayRequests();
    Http::fake(['api.botect.ai/*' => Http::response(['logged_in' => true, 'asserted_at' => '2026-09-08T00:00:00Z'])]);
    $transport = $this->app->make(LaravelHttpTransport::class);
    (new ApiClient($this->app->make(Configuration::class), $transport))->loggedIn('sess_test');
    Http::assertSent(fn (Illuminate\Http\Client\Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.botect.ai/v1/sessions/sess_test/logged-in'
        && $request->body() === ''
        && $request->hasHeader('Authorization', 'Bearer sk_test_secret'));
});

test('Laravel queue deduplicates concurrent requests for the same verdict', function (): void {
    Bus::fake();
    config(['botect.queue_connection' => 'redis', 'queue.connections.redis.driver' => 'redis']);
    $dispatcher = $this->app->make(QueueDispatcher::class);
    $delivery = Delivery::make(Operation::RefreshVerdict, 'sess_test', [], 'same-cache-key');
    expect($dispatcher->dispatch($delivery))->toBeTrue()->and($dispatcher->dispatch($delivery))->toBeTrue();
    Bus::assertDispatchedTimes(DeliverJob::class, 1);
});

test('provider registers the gated ingest route outside the web middleware group', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.ingest_path' => '/custom/ingest']);
    $router = $this->app->make(Router::class);
    (new BotectServiceProvider($this->app))->boot($router);
    $router->getRoutes()->refreshNameLookups();
    $route = $router->getRoutes()->getByName('botect.ingest');
    expect($route->uri())->toBe('custom/ingest')->and($route->gatherMiddleware())->not->toContain('web');
    $this->postJson('/custom/ingest?page_token=bad', [])->assertUnprocessable();
});

test('tracking survives a later provider re-syncing the HTTP kernel onto the router', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    $router = $this->app->make(Router::class);
    (new BotectServiceProvider($this->app))->boot($router);
    // Sanctum does exactly this from its own boot(), after ours in discovery
    // order. Every Kernel mutator that touches groups or priority copies the
    // Kernel's groups over the Router's, discarding anything pushed onto the
    // Router alone — silently, with no collector and no cookie.
    $this->app->make(HttpKernel::class)->prependToMiddlewarePriority('Tests\\LaterPackageMiddleware');
    expect($router->getMiddlewareGroups()['web'])->toContain(TrackPage::class);
    Route::get('/tracked-web-page', fn () => response('<html><head></head><body>ok</body></html>'))->middleware('web');
    $this->get('/tracked-web-page')->assertOk()->assertSee('data-botect-sdk', false);
});

test('tracking is registered once when the application already lists it in the web group', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    $kernel = $this->app->make(HttpKernel::class);
    $kernel->appendMiddlewareToGroup('web', TrackPage::class);
    (new BotectServiceProvider($this->app))->boot($this->app->make(Router::class));
    expect(array_count_values($kernel->getMiddlewareGroups()['web'])[TrackPage::class])->toBe(1);
});

test('an application without a web middleware group still boots with tracking enabled', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    $this->app->make(HttpKernel::class)->setMiddlewareGroups(['api' => []]);
    expect(fn () => (new BotectServiceProvider($this->app))->boot($this->app->make(Router::class)))->not->toThrow(Throwable::class);
});

test('manual scope leaves the web group alone and tracks only routes carrying botect.track', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true, 'botect.tracking.scope' => 'manual']);
    // Resolve the kernel first, as a real request does: the Router only
    // receives its groups when the kernel is constructed.
    $kernel = $this->app->make(HttpKernel::class);
    $router = $this->app->make(Router::class);
    (new BotectServiceProvider($this->app))->boot($router);
    expect($kernel->getMiddlewareGroups()['web'])->not->toContain(TrackPage::class)
        ->and($router->getMiddlewareGroups()['web'])->not->toContain(TrackPage::class);
    $html = '<html><head></head><body>ok</body></html>';
    Route::get('/cached-page', fn () => response($html))->middleware('web');
    Route::get('/tracked-page', fn () => response($html))->middleware(['web', 'botect.track']);
    $untracked = $this->get('/cached-page')->assertOk()->assertDontSee('data-botect-sdk', false);
    expect(collect($untracked->headers->getCookies())->map->getName()->all())->not->toContain('botect_server_session');
    $this->get('/tracked-page')->assertOk()->assertSee('data-botect-sdk', false);
    expect($this->dispatcher->forOperation(Operation::RecordPage))->toHaveCount(1);
});

test('an unknown tracking scope is refused when the provider boots', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true, 'botect.tracking.scope' => 'everywhere']);
    expect(fn () => (new BotectServiceProvider($this->app))->boot($this->app->make(Router::class)))
        ->toThrow(InvalidArgumentException::class, 'Unknown Botect tracking scope.');
});

test('a published configuration without tracking.scope keeps tracking on the web group', function (): void {
    // A config/botect.php published before the option existed replaces the
    // whole `tracking` array, so the key is absent rather than defaulted.
    config(['botect.server_ingest_enabled' => true, 'botect.tracking' => ['enabled' => true, 'inject_collector' => true, 'except' => []]]);
    expect(config('botect.tracking.scope'))->toBeNull();
    $router = $this->app->make(Router::class);
    (new BotectServiceProvider($this->app))->boot($router);
    expect($router->getMiddlewareGroups()['web'])->toContain(TrackPage::class);
});

test('Laravel maps lookup and delivery timeouts separately and jobs use the delivery limits', function (): void {
    config(['botect.connect_timeout_ms' => 300, 'botect.timeout_ms' => 900, 'botect.delivery_connect_timeout_ms' => 1500, 'botect.delivery_timeout_ms' => 6000]);
    $configuration = $this->app->make(Configuration::class);
    expect([$configuration->connectTimeoutMs, $configuration->timeoutMs, $configuration->deliveryConnectTimeoutMs, $configuration->deliveryTimeoutMs])->toBe([300, 900, 1500, 6000]);
    $this->transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
    (new DeliverJob(Delivery::make(Operation::AssertLoggedIn, 'sess_timeouts')))->handle($this->app->make(Botect::class));
    expect($this->transport->timeoutCalls)->toBe([[1500, 6000]])->and($this->transport->requests)->toHaveCount(1);
    expect($this->app->make(LaravelHttpTransport::class)->withTimeouts(1500, 6000))->toBeInstanceOf(LaravelHttpTransport::class);
});

test('encrypted Laravel web cookies survive the browser round trip', function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.tracking.enabled' => true]);
    Route::get('/cookie-page', fn () => response('<html><head></head><body>ok</body></html>'))->middleware(['web', 'botect.track']);
    $first = $this->get('/cookie-page');
    $cookie = collect($first->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'botect_server_session');
    expect($cookie)->not->toBeNull();
    $firstPayload = $this->dispatcher->forOperation(Operation::RecordPage)[0]->payload;
    $this->withUnencryptedCookie('botect_server_session', $cookie->getValue())->get('/cookie-page')->assertOk();
    $secondPayload = $this->dispatcher->forOperation(Operation::RecordPage)[1]->payload;
    expect($secondPayload['session_token'])->toBe($firstPayload['session_token'])
        ->and($secondPayload['had_session_cookie'])->toBeTrue()
        ->and($secondPayload['page_id'])->not->toBe($firstPayload['page_id']);
});

test('Laravel worker transport rejects oversized responses', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(str_repeat('a', 262145))]);
    expect(fn () => $this->app->make(LaravelHttpTransport::class)->send('GET', 'https://api.botect.ai/v1/test', [], null))
        ->toThrow(DeliveryException::class);
});

test('Laravel worker processes queued writes and releases their unique lock', function (): void {
    config(['queue.default' => 'database', 'queue.connections.database.queue' => 'application-jobs', 'queue.connections.database.driver' => 'database', 'queue.connections.database.connection' => 'testing', 'queue.connections.database.table' => 'jobs']);
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $delivery = Delivery::make(Operation::AssertLoggedIn, 'sess_worker');
    $dispatcher = $this->app->make(QueueDispatcher::class);
    $this->transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
    expect($dispatcher->dispatch($delivery))->toBeTrue()->and($this->transport->requests)->toBe([]);
    expect(config('botect.queue_connection'))->toBeNull()->and(config('botect.queue'))->toBeNull()
        ->and(DB::table('jobs')->sole()->queue)->toBe('application-jobs');
    $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($this->transport->requests)->toHaveCount(1);
    // After completion, the same unique key is available again.
    expect($dispatcher->dispatch($delivery))->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('queue connection and queue name overrides work independently', function (?string $connection, ?string $queue, string $expectedConnection): void {
    Bus::fake();
    config([
        'queue.default' => 'database',
        'queue.connections.database.driver' => 'database',
        'queue.connections.redis.driver' => 'redis',
        'botect.queue_connection' => $connection,
        'botect.queue' => $queue,
    ]);
    $dispatcher = $this->app->make(QueueDispatcher::class);
    expect($dispatcher->dispatch(Delivery::make(Operation::AssertLoggedIn, 'sess_override')))->toBeTrue();
    Bus::assertDispatched(DeliverJob::class, fn (DeliverJob $job): bool => $job->connection === $expectedConnection && $job->queue === $queue);
})->with([
    'connection only' => ['redis', null, 'redis'],
    'queue only' => [null, 'custom-botect', 'database'],
    'both' => ['redis', 'custom-botect', 'redis'],
]);
