<?php

declare(strict_types=1);
use Botect\Botect;
use Botect\Collector;
use Botect\Configuration;
use Botect\Support\PageTokens;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;

beforeEach(function (): void {
    $this->config = new Configuration('pk_test', 'sk_secret', serverIngestEnabled: true, ingestPath: '/collect/a1b2c3');
    $this->tokens = new PageTokens($this->config);
    $this->dispatcher = new FakeDispatcher;
    $this->sdk = new Botect($this->config, $this->dispatcher, new ArrayVerdictCache, new FakeTransport);
});

test('page tokens expire, resist tampering, and are scoped to site and signing key', function (): void {
    $page = $this->tokens->mint(now: 1000);
    expect($this->tokens->verify($page->token, 1001)?->sessionToken)->toBe($page->sessionToken)
        ->and($this->tokens->verify($page->token, 1900))->toBeNull()
        ->and($this->tokens->verify($page->token.'a', 1001))->toBeNull()
        ->and($this->tokens->verify($page->token, 900))->toBeNull()
        ->and((new PageTokens(new Configuration('pk_other', 'sk_secret')))->verify($page->token, 1001))->toBeNull()
        ->and((new PageTokens(new Configuration('pk_test', 'rotated_secret')))->verify($page->token, 1001))->toBeNull();
});

test('signed session cookies persist sessions but every page gets a fresh identity', function (): void {
    $page = $this->sdk->page();
    $cookie = $this->sdk->sessionCookie($page);
    $next = $this->sdk->page($cookie);
    expect($next->sessionToken)->toBe($page->sessionToken)->and($next->id)->not->toBe($page->id)
        ->and($page->hadSessionCookie)->toBeFalse()->and($next->hadSessionCookie)->toBeTrue()
        ->and($this->sdk->sessionToken($cookie.'x'))->toBeNull()
        ->and($this->sdk->page($page->sessionToken)->sessionToken)->not->toBe($page->sessionToken);
});

test('records only bounded structural request metadata', function (): void {
    $page = $this->sdk->page();
    expect($this->sdk->recordPage($page, 'GET', '/people/alice@example.com?secret=password', ['Host', 'Authorization', 'User-Agent', 'Cookie', 'X-alice@example.com', 'Accept-Language'], 'en-US,en;q=0.9,alice@example.com', 27))->toBeTrue();
    $payload = $this->dispatcher->deliveries[0]->payload;
    expect($payload['header_names'])->toBe(['host', 'user-agent', 'cookie', 'accept-language'])
        ->and($payload['languages'])->toBe(['en-us', 'en'])
        ->and($payload['ja4'])->toBeNull()->and($payload['duration_ms'])->toBe(27)
        ->and(json_encode($payload))->not->toContain('alice', 'password', 'Authorization', $page->token);
    $this->sdk->recordPage($page, 'GET', '/people/alice@example.com?other=value');
    expect($this->dispatcher->deliveries[1]->payload['path_hash'])->toBe($payload['path_hash']);
});

test('collector markup uses the current data-endpoint contract and keeps secrets out', function (): void {
    $page = $this->sdk->page();
    $html = $this->sdk->collector($page, '"><script>alert(1)</script>');
    expect($html)->toContain('data-endpoint="/collect/a1b2c3?page_token=', 'data-botect-sdk')
        ->not->toContain('sk_secret', '"><script>alert');
    expect((new Collector(new Configuration('pk_test')))->render())->toContain('data-endpoint="https://api.botect.ai/v1/events"');
});

test('proxy pins session identity to signed page and preserves batch idempotency', function (): void {
    $page = $this->sdk->page();
    $body = ['session_token' => 'attacker_chosen', 'events' => [['request_id' => 'event_1', 'type' => 'js_probe', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => ['js_passed' => true, 'collector_version' => '1.5.0']]]];
    expect($this->sdk->forwardEvents($page->token, $body, 'batch_1'))->toBeTrue();
    $this->sdk->forwardEvents($page->token, $body, 'batch_1');
    $this->sdk->forwardEvents($page->token, $body, 'batch_2');
    expect($this->dispatcher->deliveries[0]->sessionToken)->toBe($page->sessionToken)
        ->and($this->dispatcher->deliveries[0]->id)->toBe($this->dispatcher->deliveries[1]->id)
        ->and($this->dispatcher->deliveries[2]->id)->not->toBe($this->dispatcher->deliveries[0]->id)
        ->and(json_encode($this->dispatcher->deliveries[0]->payload))->not->toContain('attacker_chosen');
});

test('rejects PII and malformed event shapes before they enter the spool', function (array $payload): void {
    $body = ['events' => [['request_id' => 'e1', 'type' => 'snapshot', 'received_at' => '2026-09-08T00:00:00Z', 'payload' => $payload]]];
    expect(fn () => $this->sdk->forwardEvents($this->sdk->page()->token, $body))->toThrow(InvalidArgumentException::class);
    expect($this->dispatcher->deliveries)->toBe([]);
})->with([
    [['email' => 'alice@example.com']], [['languages' => ['alice@example.com']]],
    [['mouse_moves' => -1]], [['pointer_types' => ['keyboard']]], [['plugins' => ['private-device-name']]],
    [['page_path_shape' => '/alice@example.com']], [['mouse_entropy' => 3]],
]);

test('server features are gated until backend support exists', function (): void {
    $sdk = new Botect(new Configuration('pk_test', 'sk_secret'), $this->dispatcher, new ArrayVerdictCache, new FakeTransport);
    expect($sdk->recordPage($sdk->page(), 'GET', '/'))->toBeFalse()
        ->and($sdk->forwardEvents('anything', []))->toBeFalse()->and($this->dispatcher->deliveries)->toBe([]);
});

test('configuration rejects insecure or ambiguous upstream destinations', function (string $url): void {
    expect(fn () => new Configuration('pk_test', apiUrl: $url))->toThrow(InvalidArgumentException::class);
})->with(['http://api.example.com', '//api.example.com', 'https://user:secret@api.example.com', 'https://api.example.com?token=x', 'https://api.example.com/#fragment']);

test('accepts the botect-web collector 1.5.0 contract fixture', function (): void {
    $body = json_decode(file_get_contents(__DIR__.'/../Fixtures/collector-1.5.0.json'), true, 32, JSON_THROW_ON_ERROR);
    $body['site_key'] = 'pk_test';
    $page = $this->sdk->page();
    expect($this->sdk->forwardEvents($page->token, $body))->toBeTrue();
    $payload = $this->dispatcher->deliveries[0]->payload;
    expect($payload['events'])->toHaveCount(3)
        ->and($payload['events'][1]['payload'])->toBe($body['events'][1]['payload'])
        ->and($payload['events'][2]['payload'])->toBe($body['events'][2]['payload'])
        ->and($payload['events'][0]['payload'])->not->toHaveKey('page_path_shape');
});
