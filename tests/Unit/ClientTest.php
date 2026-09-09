<?php

declare(strict_types=1);
use Botect\ApiClient;
use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Botect\Operation;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;
use Botect\Verdict;

beforeEach(function (): void {
    $this->config = new Configuration('pk_test', 'sk_secret');
    $this->transport = new FakeTransport;
    $this->dispatcher = new FakeDispatcher;
    $this->cache = new ArrayVerdictCache;
    $this->sdk = new Botect($this->config, $this->dispatcher, $this->cache, $this->transport);
});

test('request APIs perform no HTTP and explicitly distinguish queued writes', function (): void {
    expect($this->sdk->verdict('sess_test')->action)->toBe('allow')
        ->and($this->sdk->loggedIn('sess_test'))->toBeTrue()
        ->and($this->transport->requests)->toBe([])
        ->and($this->dispatcher->deliveries)->toHaveCount(2);
    $this->dispatcher->accept = false;
    expect($this->sdk->loggedIn('sess_test'))->toBeFalse();
});

test('cached verdicts are scoped by request context and refreshed asynchronously', function (): void {
    $this->sdk->verdict('sess_test', ['path' => '/checkout']);
    $delivery = $this->dispatcher->deliveries[0];
    $this->transport->result = new Response(200, json_encode(['verdict' => 'definite', 'score' => 1, 'action' => 'block', 'detection_ids' => [12], 'reason' => 'Automation'], JSON_THROW_ON_ERROR));
    $this->sdk->deliver($delivery);
    expect($this->sdk->verdict('sess_test', ['path' => '/checkout'])->action)->toBe('block')
        ->and($this->sdk->verdict('sess_test', ['path' => '/profile'])->action)->toBe('allow')
        ->and($this->transport->requests)->toHaveCount(1)
        ->and($this->transport->requests[0]['url'])->toBe('https://api.botect.ai/v1/sessions/sess_test/verdict?path=%2Fcheckout');
});

test('a login invalidates cached verdicts and in-flight pre-login refreshes', function (): void {
    $this->sdk->verdict('sess_test');
    $old = $this->dispatcher->deliveries[0];
    $this->sdk->loggedIn('sess_test');
    $this->transport->result = new Response(200, json_encode(['verdict' => 'definite', 'score' => 1, 'action' => 'block', 'detection_ids' => [], 'reason' => 'Old evidence'], JSON_THROW_ON_ERROR));
    $this->sdk->deliver($old);
    expect($this->sdk->verdict('sess_test')->action)->toBe('allow');
});

test('unknown or unscored verdicts never stick in the cache', function (): void {
    $this->sdk->verdict('sess_test');
    $this->transport->result = new Response(200, json_encode((new Verdict)->toArray(), JSON_THROW_ON_ERROR));
    $this->sdk->deliver($this->dispatcher->deliveries[0]);
    $this->sdk->verdict('sess_test');
    expect($this->dispatcher->deliveries)->toHaveCount(2);
});

test('invalid tokens fail open without being sent or queued', function (string $token): void {
    expect($this->sdk->verdict($token)->action)->toBe('allow')
        ->and($this->sdk->loggedIn($token))->toBeFalse()
        ->and($this->dispatcher->deliveries)->toBe([]);
})->with(['', '../other', 'a?x=1', str_repeat('a', 129), "test\n"]);

test('logged-in request has no body or query and uses private authentication', function (): void {
    $this->transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
    (new ApiClient($this->config, $this->transport))->loggedIn('sess_test');
    expect($this->transport->requests[0])->toMatchArray(['method' => 'POST', 'url' => 'https://api.botect.ai/v1/sessions/sess_test/logged-in', 'body' => null])
        ->and($this->transport->requests[0]['headers']['Authorization'])->toBe('Bearer sk_secret')
        ->and($this->transport->requests[0]['headers'])->not->toHaveKey('Content-Type');
});

test('transport errors and malformed verdicts are safe retryable worker failures', function (string $body): void {
    $this->transport->result = new Response(200, $body);
    expect(fn () => (new ApiClient($this->config, $this->transport))->verdict('sess_test'))->toThrow(DeliveryException::class);
})->with(['<html>error</html>', 'null', '{}', '{"action":"block"}']);

test('classifies retryable HTTP failures without leaking response bodies', function (int $status, bool $retryable): void {
    $this->transport->result = new Response($status, 'sensitive diagnostic detail');
    try {
        (new ApiClient($this->config, $this->transport))->loggedIn('sess_test');
        test()->fail('Expected failure');
    } catch (DeliveryException $exception) {
        expect($exception->retryable)->toBe($retryable)->and($exception->getMessage())->not->toContain('sensitive');
    }
})->with([[401, false], [403, false], [404, true], [422, false], [429, true], [503, true], [302, false]]);

test('private API operations never run with only a site key', function (): void {
    $sdk = new Botect(new Configuration('pk_test'), $this->dispatcher, $this->cache, $this->transport);
    expect($sdk->verdict('sess_test')->action)->toBe('allow')->and($sdk->loggedIn('sess_test'))->toBeFalse()
        ->and($this->dispatcher->deliveries)->toBe([]);
});

test('legacy public ingest is never used to forward visitor events', function (): void {
    expect(fn () => (new ApiClient($this->config, $this->transport))->serverIngest(Operation::ForwardEvents, [], str_repeat('a', 64)))->toThrow(DeliveryException::class);
    expect($this->transport->requests)->toBe([]);
});

test('expired deliveries cannot make late login assertions', function (): void {
    $delivery = new Delivery(Operation::AssertLoggedIn, 'sess_test', [], str_repeat('a', 64), time() - 3601);
    expect(fn () => $this->sdk->deliver($delivery))->toThrow(DeliveryException::class);
    expect($this->transport->requests)->toBe([]);
});

test('cache and dispatcher outages cannot escape the verdict API', function (): void {
    $dispatcher = new class implements Dispatcher
    {
        public function dispatch(Delivery $delivery): bool
        {
            throw new RuntimeException('Queue unavailable');
        }
    };
    $sdk = new Botect($this->config, $dispatcher, $this->cache, $this->transport);
    expect($sdk->verdict('sess_test')->action)->toBe('allow')->and($sdk->loggedIn('sess_test'))->toBeFalse();
});

test('verified bot identity remains enforceable even without a numeric score', function (): void {
    $this->sdk->verdict('sess_verified');
    $this->transport->result = new Response(200, '{"verdict":"verified","score":0,"action":"block","detection_ids":[],"reason":"Customer rule blocks this verified crawler"}');
    $this->sdk->deliver($this->dispatcher->deliveries[0]);
    expect($this->sdk->verdict('sess_verified')->action)->toBe('block')
        ->and($this->sdk->verdict('sess_verified')->available())->toBeTrue();
});
