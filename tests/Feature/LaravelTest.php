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
use Botect\Laravel\QueueDispatcher;
use Botect\Operation;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;
use Botect\Verdict;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
    Bus::assertDispatched(DeliverJob::class, fn (DeliverJob $job): bool => $job->queue === 'botect' && $job->connection === 'redis');
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
    config(['botect.queue_connection' => 'database', 'queue.connections.database.driver' => 'database', 'queue.connections.database.connection' => 'testing', 'queue.connections.database.table' => 'jobs']);
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
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'botect', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect($this->transport->requests)->toHaveCount(1);
    // After completion, the same unique key is available again.
    expect($dispatcher->dispatch($delivery))->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1);
});
