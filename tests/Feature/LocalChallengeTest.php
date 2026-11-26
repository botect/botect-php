<?php

declare(strict_types=1);

use Botect\Botect;
use Botect\ClientIp;
use Botect\Configuration;
use Botect\Contracts\ChallengeRenderer;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\EnforcementStore;
use Botect\Contracts\HttpTransport;
use Botect\Http\Response;
use Botect\Laravel\BotectServiceProvider;
use Botect\Laravel\Contracts\ClientIpResolver;
use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Laravel\Http\ChallengeController;
use Botect\Laravel\Jobs\DeliverJob;
use Botect\Laravel\Middleware\EnforceVerdict;
use Botect\Laravel\Middleware\TrackPage;
use Botect\Operation;
use Botect\Support\ChallengeTokens;
use Botect\Support\HeaderShape;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeLogger;
use Botect\Tests\FakeTransport;
use Botect\Verdict;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

require_once __DIR__.'/../ChallengeFixtures.php';

function sdkLocalRequest(string $method = 'GET', array $headers = [], array $cookies = []): Request
{
    return Request::create('https://customer.test/protected?q=one', $method, [], $cookies, [], array_replace([
        'REMOTE_ADDR' => '8.8.8.8', 'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'Browser',
    ], $headers));
}

function sdkLocalRun($app, Request $request, bool $tracking = false): HttpResponse
{
    $next = fn () => response('<html><head></head><body>application</body></html>', 200, ['Content-Type' => 'text/html']);
    $enforce = fn ($request) => (new EnforceVerdict($app))->handle($request, $next);

    return $tracking ? (new TrackPage($app))->handle($request, $enforce) : $enforce($request);
}

beforeEach(function (): void {
    config(['botect.server_ingest_enabled' => true, 'botect.enforcement.enabled' => true, 'botect.enforcement.local_challenges.enabled' => true]);
    $this->dispatcher = new FakeDispatcher;
    $this->transport = new FakeTransport;
    $this->logger = new FakeLogger;
    $this->app->instance(Dispatcher::class, $this->dispatcher);
    $this->app->instance(HttpTransport::class, $this->transport);
    $this->app->instance(LoggerInterface::class, $this->logger);
    $this->request = sdkLocalRequest();
    sdkSeedEnforcement($this->app->make(EnforcementStore::class), $this->app->make(Configuration::class), sdkChallengeListData(['header_shapes' => [HeaderShape::hash(array_keys($this->request->headers->all()))]]));
});

test('first-visit denial reports the original GET once and never injects collector', function (string $action): void {
    config(['botect.tracking.enabled' => true]);
    if ($action === 'block') {
        config(['botect.enforcement.local_challenges.action' => 'block']);
        $this->app->forgetInstance(Configuration::class);
    }
    $calls = 0;
    $this->app->instance(ClientIpResolver::class, new class($calls) implements ClientIpResolver
    {
        public function __construct(public int &$calls) {}

        public function resolve(Request $request): ?ClientIp
        {
            $this->calls++;

            return new ClientIp('8.8.8.8', 'resolver');
        }
    });
    $response = sdkLocalRun($this->app, $this->request, true);
    expect($response->getStatusCode())->toBe(403)->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->getContent())->not->toContain('data-botect-sdk')->and($calls)->toBe(1)
        ->and($this->dispatcher->forOperation(Operation::RecordPage))->toHaveCount(1)->and($this->transport->requests)->toBe([])
        ->and($this->request->attributes->get('botect.page_reported'))->toBeTrue();
    $payload = $this->dispatcher->forOperation(Operation::RecordPage)[0]->payload;
    expect($payload['header_names'])->toBe(HeaderShape::normalize(array_keys($this->request->headers->all())))
        ->and($payload['method'])->toBe('GET')->and($payload['logged_in'])->toBeFalse()->and($payload['had_session_cookie'])->toBeFalse();
    $events = array_values(array_filter($this->logger->records, fn ($record) => $record[1] === $action.'_issued'));
    expect($events)->toHaveCount(1)->and($events[0][2])->toBe(['source' => 'crowd', 'network_prefix' => '8.8.8.0/24', 'reason' => 'header_shape', 'shape' => HeaderShape::hash(array_keys($this->request->headers->all()))]);
    if ($action === 'block') {
        expect($response->getContent())->toBe('Forbidden')->and($response->headers->get('Content-Type'))->toStartWith('text/plain');
    } else {
        expect($response->getContent())->toContain('JavaScript and cookies', 'nonce=')->and($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'self'");
    }
})->with(['challenge', 'block']);

test('each Laravel bypass prevents crowd denial', function (string $method, array $headers, ?bool $loggedIn, bool $excluded): void {
    if ($loggedIn === null) {
        config(['botect.logged_in.resolver' => null]);
    }
    if ($loggedIn === true) {
        $this->request->attributes->set('botect.page', $this->app->make(Botect::class)->page(loggedIn: true));
    }
    $request = sdkLocalRequest($method, $headers);
    if ($loggedIn === true) {
        $request->attributes->set('botect.page', $this->request->attributes->get('botect.page'));
    }
    if ($excluded) {
        config(['botect.enforcement.local_challenges.except' => ['protected']]);
    }
    sdkSeedEnforcement($this->app->make(EnforcementStore::class), $this->app->make(Configuration::class), sdkChallengeListData(['header_shapes' => [HeaderShape::hash(array_keys($request->headers->all()))]]));
    expect(sdkLocalRun($this->app, $request)->getStatusCode())->toBe(200)->and($this->dispatcher->forOperation(Operation::RecordPage))->toBe([]);
})->with([
    ['HEAD', [], false, false], ['POST', [], false, false], ['GET', ['HTTP_ACCEPT' => 'application/json'], false, false],
    ['GET', ['HTTP_ACCEPT' => 'text/html;q=0'], false, false],
    ['GET', ['HTTP_ACCEPT' => 'text/html;q=0, */*;q=1'], false, false],
    ['GET', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], false, false], ['GET', ['HTTP_SEC_PURPOSE' => 'prefetch;prerender'], false, false],
    ['GET', ['HTTP_PURPOSE' => 'prefetch'], false, false], ['GET', [], null, false], ['GET', [], true, false], ['GET', [], false, true],
]);

test('parent gate and both local gates retain default-off behavior', function (string $setting): void {
    config([$setting => false]);
    $this->app->forgetInstance(Configuration::class);
    expect(sdkLocalRun($this->app, $this->request)->getStatusCode())->toBe(200)->and($this->dispatcher->forOperation(Operation::RecordPage))->toBe([]);
})->with(['botect.enforcement.enabled', 'botect.enforcement.local_challenges.enabled', 'botect.server_ingest_enabled']);

test('dispatcher refusal allows the host response and produces no issuance event', function (): void {
    $this->dispatcher->accept = false;
    expect(sdkLocalRun($this->app, $this->request)->getStatusCode())->toBe(200)->and($this->logger->records)->toBe([]);
});

test('clearance skips challenge without logging but cannot skip configured block', function (): void {
    $sdk = $this->app->make(Botect::class);
    $page = $sdk->page(loggedIn: false);
    $cookie = (new ChallengeTokens($sdk->configuration))->clearance($page->sessionToken, new ClientIp('8.8.8.8', 'request'), 'Browser', 'https://customer.test');
    $request = sdkLocalRequest(cookies: ['botect_server_session' => $sdk->sessionCookie($page), 'botect_challenge_pass' => $cookie]);
    expect(sdkLocalRun($this->app, $request)->getStatusCode())->toBe(200)->and($this->dispatcher->forOperation(Operation::RecordPage))->toBe([])->and($this->logger->records)->toBe([]);
    config(['botect.enforcement.local_challenges.action' => 'block']);
    $this->app->forgetInstance(Configuration::class);
    $this->app->forgetInstance(Botect::class);
    expect(sdkLocalRun($this->app, sdkLocalRequest(cookies: $request->cookies->all()))->getStatusCode())->toBe(403);
});

test('custom handlers retain control and null continues the application', function (): void {
    $this->app->instance(VerdictHandler::class, new class implements VerdictHandler
    {
        public function handle(Request $request, Verdict $verdict): ?HttpResponse
        {
            return null;
        }
    });
    expect(sdkLocalRun($this->app, $this->request)->getStatusCode())->toBe(200)->and($this->logger->records)->toBe([]);
});

test('custom renderers replace only HTML and retain proof verification', function (): void {
    $this->app->instance(ChallengeRenderer::class, new class implements ChallengeRenderer
    {
        public function render(array $context): string
        {
            expect(array_keys($context))->toContain('ticket', 'nonce', 'verification_path', 'return_to', 'csp_nonce');

            return '<h1>Custom check</h1>';
        }
    });
    expect(sdkLocalRun($this->app, $this->request)->getContent())->toBe('<h1>Custom check</h1>');
});

test('verification accepts encrypted session cookie and issues secure host-only clearance', function (): void {
    $sdk = $this->app->make(Botect::class);
    $page = $sdk->page(loggedIn: false);
    $issued = (new ChallengeTokens($sdk->configuration))->issue($page, new ClientIp('8.8.8.8', 'request'), 'Browser', 'https://customer.test', '/', 'verdict');
    $encrypter = $this->app->make(Encrypter::class);
    $cookie = $encrypter->encrypt(CookieValuePrefix::create('botect_server_session', $encrypter->getKey()).$sdk->sessionCookie($page), false);
    $proof = ['ticket' => $issued['ticket'], 'answer' => hash('sha256', 'botect-js-v1:'.$issued['nonce'])];
    $request = Request::create('https://customer.test/_botect/challenge', 'POST', [], ['botect_server_session' => $cookie], [], ['REMOTE_ADDR' => '8.8.8.8', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://customer.test', 'HTTP_USER_AGENT' => 'Browser'], json_encode($proof));
    $response = (new ChallengeController)($request);
    expect($response->getStatusCode())->toBe(204);
    $clearance = $response->headers->getCookies()[0];
    expect($clearance->getName())->toBe('botect_challenge_pass')->and($clearance->isHttpOnly())->toBeTrue()->and($clearance->isSecure())->toBeTrue()
        ->and($clearance->getDomain())->toBeNull()->and($clearance->getPath())->toBe('/')->and($clearance->getSameSite())->toBe('lax')
        ->and($this->dispatcher->forOperation(Operation::RecordPage))->toBe([])->and($this->logger->records[0][1])->toBe('clearance_issued')
        ->and($this->logger->records[0][2])->toBe(['source' => 'verdict', 'network_prefix' => '8.8.8.0/24']);
});

test('verifier rejects foreign origins, oversized, non-JSON and bad proofs without clearance', function (array $server, string $body): void {
    $request = Request::create('https://customer.test/_botect/challenge', 'POST', [], [], [], array_replace(['REMOTE_ADDR' => '8.8.8.8', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://customer.test'], $server), $body);
    $response = (new ChallengeController)($request);
    expect($response->getStatusCode())->toBe(403)->and($response->headers->getCookies())->toBe([])->and($response->headers->get('Cache-Control'))->toContain('no-store');
})->with([
    [['HTTP_ORIGIN' => 'https://evil.test'], '{}'], [['HTTP_ORIGIN' => 'null'], '{}'], [['HTTP_SEC_FETCH_SITE' => 'cross-site'], '{}'],
    [['CONTENT_TYPE' => 'text/plain'], '{}'], [[], str_repeat('x', 4097)], [[], '{'], [[], '{"ticket":"bad","answer":"bad"}'],
]);

test('an anonymous denial cannot be replayed to an exempt visitor at the same URL', function (): void {
    $first = sdkLocalRun($this->app, sdkLocalRequest());
    config(['botect.logged_in.resolver' => null]);
    $second = sdkLocalRun($this->app, sdkLocalRequest());
    expect($first->getStatusCode())->toBe(403)->and($first->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($second->getStatusCode())->toBe(200)->and($second->getContent())->toContain('application')->not->toContain('Checking your browser');
});

test('session challenge uses the page even with crowd action block and existing block wins', function (): void {
    config(['botect.enforcement.local_challenges.action' => 'block']);
    $this->app->forgetInstance(Configuration::class);
    $sdk = $this->app->make(Botect::class);
    $page = $sdk->page(loggedIn: false);
    $sdk->verdict($page->sessionToken, [], false);
    $this->transport->result = new Response(200, json_encode(['verdict' => 'definite', 'score' => 1, 'action' => 'challenge', 'detection_ids' => [], 'reason' => 'Server decision']));
    $sdk->deliver($this->dispatcher->deliveries[0]);
    $request = sdkLocalRequest(cookies: ['botect_server_session' => $sdk->sessionCookie($page)]);
    $response = sdkLocalRun($this->app, $request);
    expect($response->getStatusCode())->toBe(403)->and($response->getContent())->toContain('Checking your browser')
        ->and($this->dispatcher->forOperation(Operation::RecordPage))->toBe([]);
});

test('provider mounts verifier outside web and refuses invalid action at boot', function (): void {
    $provider = new BotectServiceProvider($this->app);
    $router = $this->app->make(Router::class);
    $provider->boot($router);
    $router->getRoutes()->refreshNameLookups();
    expect($router->getRoutes()->getByName('botect.challenge')->gatherMiddleware())->not->toContain('web');
    config(['botect.enforcement.local_challenges.action' => 'deny']);
    expect(fn () => $provider->boot($router))->toThrow(InvalidArgumentException::class);
});

test('refresh queue retries honor the store backoff and preserve their envelope', function (): void {
    $sdk = $this->app->make(Botect::class);
    expect($sdk->dispatchChallengeListRefresh())->toBeTrue();
    $delivery = $this->dispatcher->forOperation(Operation::RefreshChallengeList)[0];
    $job = unserialize(serialize(new DeliverJob($delivery)));
    $job->withFakeQueueInteractions();
    $this->transport->result = new Response(503, '<html>outage</html>');
    $job->handle($sdk);
    $job->assertReleased(30);
    $job->assertNotFailed();
    expect($job->delivery->toArray())->toBe($delivery->toArray());
    $job->handle($sdk);
    $job->assertReleased(30);
    expect($this->transport->requests)->toHaveCount(1);
});

test('web encryption round trip preserves the verifier clearance on the next protected GET', function (): void {
    (new BotectServiceProvider($this->app))->boot($this->app->make(Router::class));
    $seen = [];
    Route::get('/round-trip', function (Request $request) use (&$seen) {
        $seen = array_keys($request->headers->all());

        return response('<html><head></head><body>application</body></html>', 200, ['Content-Type' => 'text/html']);
    })->middleware(['web', 'botect.enforce']);
    $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);
    $headers = ['Accept' => 'text/html', 'User-Agent' => 'Browser'];
    $denial = $this->get('https://customer.test/round-trip', $headers)->assertForbidden();
    preg_match('/const c=(\{.*?\});/s', $denial->getContent(), $matches);
    $context = json_decode($matches[1], true, 8, JSON_THROW_ON_ERROR);
    $sessionCookie = collect($denial->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'botect_server_session');
    $this->withUnencryptedCookie('botect_server_session', $sessionCookie->getValue());
    $verified = $this->withCredentials()->postJson('https://customer.test/_botect/challenge', [
        'ticket' => $context['ticket'], 'answer' => hash('sha256', 'botect-js-v1:'.$context['nonce']),
    ], ['Origin' => 'https://customer.test', 'User-Agent' => 'Browser'])->assertNoContent();
    $clearance = collect($verified->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'botect_challenge_pass');
    $this->withUnencryptedCookie('botect_challenge_pass', $clearance->getValue());
    $this->get('https://customer.test/round-trip', $headers)->assertOk();
});

test('Laravel snapshot readers do not contend on the mutation lock', function (): void {
    $scope = $this->app->make(Configuration::class)->enforcementScope();
    $cache = $this->app->make('cache')->store(config('botect.cache_store'));
    $lock = $cache->getStore()->lock('botect:enforcement:v1:'.$scope.':lock', 5);
    expect($lock->get())->toBeTrue();
    try {
        expect(sdkLocalRun($this->app, $this->request)->getStatusCode())->toBe(403)
            ->and($this->dispatcher->forOperation(Operation::RecordPage))->toHaveCount(1);
    } finally {
        $lock->release();
    }
});

test('challenge clearance never bypasses an independent cached session block or delay', function (string $action, int $status): void {
    $sdk = $this->app->make(Botect::class);
    $page = $sdk->page(loggedIn: false);
    $sdk->verdict($page->sessionToken, [], false);
    $this->transport->result = new Response(200, json_encode(['verdict' => 'definite', 'score' => 1, 'action' => $action, 'detection_ids' => [], 'reason' => 'Server policy']));
    $sdk->deliver($this->dispatcher->deliveries[0]);
    $clearance = (new ChallengeTokens($sdk->configuration))->clearance($page->sessionToken, new ClientIp('8.8.8.8', 'request'), 'Browser', 'https://customer.test');
    $response = sdkLocalRun($this->app, sdkLocalRequest(cookies: ['botect_server_session' => $sdk->sessionCookie($page), 'botect_challenge_pass' => $clearance]));
    expect($response->getStatusCode())->toBe($status)->and($response->getContent())->not->toContain('Checking your browser')
        ->and($this->dispatcher->forOperation(Operation::RecordPage))->toBe([]);
})->with([['block', 403], ['delay', 429]]);

test('missing Accept is eligible while every other navigation check still applies', function (): void {
    $request = sdkLocalRequest();
    $request->headers->remove('Accept');
    sdkSeedEnforcement($this->app->make(EnforcementStore::class), $this->app->make(Configuration::class), sdkChallengeListData(['header_shapes' => [HeaderShape::hash(array_keys($request->headers->all()))]]));
    expect(sdkLocalRun($this->app, $request)->getStatusCode())->toBe(403);
    foreach (['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_PURPOSE' => 'prefetch', 'CONTENT_TYPE' => 'application/json'] as $header => $value) {
        $excluded = sdkLocalRequest(headers: [$header => $value]);
        $excluded->headers->remove('Accept');
        expect(sdkLocalRun($this->app, $excluded)->getStatusCode())->toBe(200);
    }
});

test('a throwing bundled renderer and rejected target do not prevent a custom challenge handler', function (): void {
    $this->app->instance(ChallengeRenderer::class, new class implements ChallengeRenderer
    {
        public function render(array $context): string
        {
            throw new RuntimeException('Must not render.');
        }
    });
    $this->app->instance(VerdictHandler::class, new class implements VerdictHandler
    {
        public function handle(Request $request, Verdict $verdict): ?HttpResponse
        {
            return redirect('/customer-challenge');
        }
    });
    $request = sdkLocalRequest();
    // A raw space remains invalid for the bundled protocol, but custom handlers own their response.
    $request->server->set('REQUEST_URI', '/protected?q=raw space');
    expect(sdkLocalRun($this->app, $request)->getStatusCode())->toBe(302);
});
