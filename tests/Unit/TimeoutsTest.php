<?php

declare(strict_types=1);
use Botect\Botect;
use Botect\Configuration;
use Botect\Contracts\HttpTransport;
use Botect\Http\CurlTransport;
use Botect\Http\Response;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;

test('delivery timeouts default longer than lookup timeouts and are validated', function (): void {
    $config = new Configuration('pk_test', 'sk_secret');
    expect([$config->connectTimeoutMs, $config->timeoutMs, $config->deliveryConnectTimeoutMs, $config->deliveryTimeoutMs])->toBe([200, 1000, 1000, 5000]);
    $retimed = $config->withTimeouts(1000, 5000);
    expect([$retimed->connectTimeoutMs, $retimed->timeoutMs])->toBe([1000, 5000])
        ->and($retimed->siteKey)->toBe('pk_test')
        ->and($retimed->deliveryTimeoutMs)->toBe(5000);
    foreach ([[0, 5000], [2000, 1000], [1000, 10001]] as [$connect, $total]) {
        expect(fn () => new Configuration('pk_test', 'sk_secret', deliveryConnectTimeoutMs: $connect, deliveryTimeoutMs: $total))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $config->withTimeouts(500, 100))->toThrow(InvalidArgumentException::class);
});

test('background deliveries use the delivery timeouts and immediate lookups the lookup timeouts', function (): void {
    $config = new Configuration('pk_test', 'sk_secret', deliveryConnectTimeoutMs: 1500, deliveryTimeoutMs: 6000);
    $transport = new FakeTransport;
    $dispatcher = new FakeDispatcher;
    $sdk = new Botect($config, $dispatcher, new ArrayVerdictCache, $transport);
    $transport->result = new Response(200, json_encode(['verdict' => 'definite', 'score' => 1, 'action' => 'block', 'detection_ids' => [12], 'reason' => 'Automation'], JSON_THROW_ON_ERROR));
    expect($sdk->lookupVerdict('sess_test')->action)->toBe('block')
        ->and($transport->timeoutCalls)->toBe([]);
    $sdk->loggedIn('sess_test');
    $transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
    $sdk->deliver($dispatcher->deliveries[0]);
    expect($transport->timeoutCalls)->toBe([[1500, 6000]])
        ->and($transport->requests)->toHaveCount(2);
});

test('deferred delivery keeps the lookup timeouts because it holds the request worker', function (): void {
    $transport = new FakeTransport;
    $transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
    $sdk = Botect::create(new Configuration('pk_test', 'sk_secret'), transport: $transport);
    expect($sdk->loggedIn('sess_test'))->toBeTrue();
    expect($sdk->sendPending()->sent)->toBe(1)
        ->and($transport->timeoutCalls)->toBe([]);
});

test('a transport that cannot be re-timed is used as built', function (): void {
    $transport = new class implements HttpTransport
    {
        /** @var list<string> */
        public array $urls = [];

        public function send(string $method, string $url, array $headers, ?string $body): Response
        {
            $this->urls[] = $url;

            return new Response(200, '{"logged_in":true,"asserted_at":"2026-09-08T00:00:00Z"}');
        }
    };
    $dispatcher = new FakeDispatcher;
    $sdk = new Botect(new Configuration('pk_test', 'sk_secret'), $dispatcher, new ArrayVerdictCache, $transport);
    $sdk->loggedIn('sess_test');
    $sdk->deliver($dispatcher->deliveries[0]);
    expect($transport->urls)->toHaveCount(1);
});

test('the curl transport re-times into a copy and leaves the original alone', function (): void {
    $transport = new CurlTransport(new Configuration('pk_test', 'sk_secret'));
    $retimed = $transport->withTimeouts(1000, 5000);
    expect($retimed)->toBeInstanceOf(CurlTransport::class)->and($retimed)->not->toBe($transport);
});
