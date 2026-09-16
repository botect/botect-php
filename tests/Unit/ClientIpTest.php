<?php

declare(strict_types=1);
use Botect\Actions\DeliverAction;
use Botect\ApiClient;
use Botect\ClientIp;
use Botect\Configuration;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Operation;
use Botect\Storage\MemoryVerdictCache;
use Botect\Tests\FakeTransport;

test('only public addresses become a client IP', function (?string $raw, ?string $expected): void {
    $ip = ClientIp::fromString($raw, ClientIp::SOURCE_REQUEST);
    expect($ip?->ip)->toBe($expected);
})->with([
    'public v4' => ['203.0.113.7', '203.0.113.7'],
    'public v6 canonicalised' => ['2001:0db8:0000::0001', '2001:db8::1'],
    'swarm overlay' => ['10.0.0.2', null],
    'rfc1918' => ['192.168.1.9', null],
    'loopback' => ['127.0.0.1', null],
    'link-local' => ['169.254.10.10', null],
    'v6 unique local' => ['fd00::1', null],
    'v6 loopback' => ['::1', null],
    'garbage' => ['not-an-ip', null],
    'empty' => ['', null],
    'null' => [null, null],
]);

test('a client IP records its source and whether it was inferred', function (): void {
    $explicit = ClientIp::fromString('203.0.113.7', ClientIp::SOURCE_HEADER);
    $guessed = ClientIp::fromString('203.0.113.7', ClientIp::SOURCE_HEADER, true);
    expect($explicit->toPayload())->toBe(['observed_ip' => '203.0.113.7', 'observed_ip_source' => 'header', 'observed_ip_inferred' => false])
        ->and($guessed->inferred)->toBeTrue()
        ->and(fn () => new ClientIp('203.0.113.7', 'guess'))->toThrow(InvalidArgumentException::class);
});

test('a stale delivery is dropped with a message that says so', function (): void {
    $configuration = new Configuration('pk_test', 'sk_test_secret', serverIngestEnabled: true);
    $action = new DeliverAction(new ApiClient($configuration, new FakeTransport), new MemoryVerdictCache, $configuration);
    $stale = new Delivery(Operation::RecordPage, 'sess_'.str_repeat('a', 48), [], str_repeat('b', 64), time() - DeliverAction::MAX_AGE_SECONDS - 300);
    try {
        $action->execute($stale);
        $this->fail('expected the stale delivery to be rejected');
    } catch (DeliveryException $exception) {
        expect($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('expired', '3600s limit')
            ->and($exception->getMessage())->not->toContain('transport');
    }
});
