<?php

declare(strict_types=1);
use Botect\Botect;
use Botect\Configuration;
use Botect\Exceptions\DeliveryException;
use Botect\Http\CurlTransport;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;

test('a delivery failure keeps the transport error as its cause, with session tokens redacted', function (): void {
    $transport = new FakeTransport;
    $transport->result = new RuntimeException('cURL error 28: Operation timed out after 5000 milliseconds for https://api.botect.ai/v1/sessions/sess_abc123DEF/verdict');
    $dispatcher = new FakeDispatcher;
    $sdk = new Botect(new Configuration('pk_test', 'sk_secret'), $dispatcher, new ArrayVerdictCache, $transport);
    $sdk->loggedIn('sess_abc123DEF');
    try {
        $sdk->deliver($dispatcher->deliveries[0]);
        $this->fail('The delivery should have failed.');
    } catch (DeliveryException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->getPrevious())->toBe($transport->result)
            ->and($exception->getMessage())->toContain('Botect transport or response failure.', 'Cause: cURL error 28', 'sess_[redacted]')
            ->and($exception->getMessage())->not->toContain('sess_abc123DEF');
    }
});

test('a cause message is capped so a huge response body cannot flood the log', function (): void {
    $exception = new DeliveryException(true, previous: new RuntimeException(str_repeat('x', 1000)));
    expect(strlen($exception->getMessage()))->toBeLessThan(260)
        ->and($exception->getMessage())->toEndWith('…');
});

test('an HTTP status failure still reads as before and carries no cause', function (): void {
    $exception = new DeliveryException(true, 503);
    expect($exception->getMessage())->toBe('Botect delivery returned HTTP 503.')
        ->and($exception->getPrevious())->toBeNull();
});

test('the curl transport reports why the connection failed', function (): void {
    // A closed local port refuses at once; no external network involved.
    $transport = new CurlTransport(new Configuration('pk_test', 'sk_secret', apiUrl: 'https://127.0.0.1:9/v1'));
    try {
        $transport->send('GET', 'https://127.0.0.1:9/v1/sessions/sess_x/verdict', [], null);
        $this->fail('Nothing listens on port 9.');
    } catch (DeliveryException $exception) {
        expect($exception->retryable)->toBeTrue()
            ->and($exception->getPrevious())->toBeInstanceOf(RuntimeException::class)
            ->and($exception->getPrevious()->getMessage())->not->toBe('')
            ->and($exception->getMessage())->toContain('Cause: ');
    }
});

test('validation fields are sanitized before storing or rendering', function (): void {
    $exception = new DeliveryException(false, 422, validationFields: [
        'events.2.sent_at', 'events.0.payload.mouse_moves', 'events.2.sent_at', 'events.*.sent_at',
        "events.0.sent_at\nsecret", 'alice@example.com', 'session.sess_private', 'sk_secret', 'pk_secret', 'bt_private',
        '', 'events..sent_at', str_repeat('x', 129), 42, null,
    ]);
    expect($exception->validationFields)->toBe(['events.*.sent_at', 'events.0.payload.mouse_moves', 'events.2.sent_at'])
        ->and($exception->getMessage())->toBe('Botect delivery returned HTTP 422. Invalid fields: events.*.sent_at, events.0.payload.mouse_moves, events.2.sent_at.')
        ->and($exception->getPrevious())->toBeNull();
});

test('validation field limits sort and deduplicate before capping', function (): void {
    $fields = array_map(fn (int $i): string => 'events.'.$i.'.sent_at', range(0, 120));
    $fields[] = str_repeat('x', 128);
    $expected = $fields;
    sort($expected, SORT_STRING);
    $exception = new DeliveryException(false, 422, validationFields: array_reverse([...$fields, ...$fields]));
    expect($exception->validationFields)->toBe(array_slice($expected, 0, 100));
    expect((new DeliveryException(false, 422, validationFields: [str_repeat('x', 128)]))->validationFields)->toBe([str_repeat('x', 128)]);
});

test('legacy constructor arguments and custom messages remain compatible', function (): void {
    $cause = new RuntimeException('timeout sess_private');
    $exception = new DeliveryException(true, 0, $cause, 'Custom failure.');
    expect($exception->getMessage())->toBe('Custom failure. Cause: timeout sess_[redacted]')
        ->and($exception->getPrevious())->toBe($cause)
        ->and($exception->validationFields)->toBe([]);
});
