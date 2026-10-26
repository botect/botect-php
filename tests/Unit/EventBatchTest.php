<?php

declare(strict_types=1);

use Botect\Support\EventBatch;

test('timestamp aliases preserve browser precision and emit only sent_at', function (array $timestamps, string $expected): void {
    $event = ['request_id' => 'e1', 'type' => 'snapshot', 'payload' => []] + $timestamps;
    $events = EventBatch::validate(['events' => [$event]]);
    expect($events[0]['sent_at'])->toBe($expected)
        ->and($events[0])->not->toHaveKey('received_at')->not->toHaveKey('relayed_at');
})->with([
    'legacy' => [['received_at' => '2026-01-01T00:00:00.123Z'], '2026-01-01T00:00:00.123Z'],
    'new' => [['sent_at' => '2026-01-01T00:00:00.123456Z'], '2026-01-01T00:00:00.123456Z'],
    'equal instants' => [['sent_at' => '2026-01-01T00:00:00.1Z', 'received_at' => '2026-01-01T00:00:00.100000Z'], '2026-01-01T00:00:00.1Z'],
    'lower bound' => [['sent_at' => '1970-01-02T00:00:00Z'], '1970-01-02T00:00:00Z'],
    'upper bound' => [['sent_at' => '2038-01-18T00:00:00.000000Z'], '2038-01-18T00:00:00.000000Z'],
]);

test('each supplied alias must be a strict valid bounded UTC timestamp', function (mixed $timestamp, string $alias): void {
    $event = ['request_id' => 'e1', 'type' => 'page', $alias => $timestamp];
    expect(fn () => EventBatch::validate(['events' => [$event]]))->toThrow(InvalidArgumentException::class);
})->with([
    'null' => null, 'integer' => 123, 'array' => [[]], 'empty' => '',
    'bad calendar' => '2026-02-30T00:00:00Z', 'bad month' => '2026-13-01T00:00:00Z',
    'bad hour' => '2026-01-01T25:00:00Z', 'leap second' => '2026-01-01T00:00:60Z',
    'offset' => '2026-01-01T00:00:00+00:00', 'lowercase z' => '2026-01-01T00:00:00z',
    'lowercase t' => '2026-01-01t00:00:00Z', 'space' => '2026-01-01 00:00:00Z',
    'trailing newline' => "2026-01-01T00:00:00Z\n", 'leading space' => ' 2026-01-01T00:00:00Z',
    'seven fractional digits' => '2026-01-01T00:00:00.1234567Z', 'empty fraction' => '2026-01-01T00:00:00.Z',
    'below bound' => '1970-01-01T23:59:59.999999Z', 'above bound' => '2038-01-18T00:00:00.000001Z',
])->with(['sent_at', 'received_at']);

test('missing, invalid, or conflicting aliases reject the whole batch', function (array $timestamps): void {
    $good = ['request_id' => 'good', 'type' => 'page', 'sent_at' => '2026-01-01T00:00:00Z'];
    $bad = ['request_id' => 'bad', 'type' => 'page'] + $timestamps;
    expect(fn () => EventBatch::validate(['events' => [$good, $bad]]))->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [[]],
    'null legacy with valid new' => [['sent_at' => '2026-01-01T00:00:00Z', 'received_at' => null]],
    'null new with valid legacy' => [['sent_at' => null, 'received_at' => '2026-01-01T00:00:00Z']],
    'invalid legacy with valid new' => [['sent_at' => '2026-01-01T00:00:00Z', 'received_at' => 'bad']],
    'invalid new with valid legacy' => [['sent_at' => 'bad', 'received_at' => '2026-01-01T00:00:00Z']],
    'different microseconds' => [['sent_at' => '2026-01-01T00:00:00.1Z', 'received_at' => '2026-01-01T00:00:00.100001Z']],
]);

test('mixed aliases accept old and future browser times without a recency window', function (): void {
    $events = EventBatch::validate(['events' => [
        ['request_id' => 'old', 'type' => 'page', 'received_at' => '1971-01-01T00:00:00Z'],
        ['request_id' => 'future', 'type' => 'page', 'sent_at' => '2037-01-01T00:00:00Z'],
    ]]);
    expect(array_column($events, 'sent_at'))->toBe(['1971-01-01T00:00:00Z', '2037-01-01T00:00:00Z']);
});

test('browser relay timestamps are rejected at body and event level', function (bool $topLevel): void {
    $body = ['events' => [['request_id' => 'e1', 'type' => 'page', 'sent_at' => '2026-01-01T00:00:00Z']]];
    if ($topLevel) {
        $body['relayed_at'] = '2026-01-01T00:00:00Z';
    } else {
        $body['events'][0]['relayed_at'] = '2026-01-01T00:00:00Z';
    }
    expect(fn () => EventBatch::validate($body))->toThrow(InvalidArgumentException::class);
})->with([true, false]);
