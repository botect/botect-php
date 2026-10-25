<?php

declare(strict_types=1);
use Botect\Botect;
use Botect\Configuration;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Botect\Operation;
use Botect\Storage\FileSpool;
use Botect\Storage\FileVerdictCache;
use Botect\Testing\ArrayVerdictCache;
use Botect\Tests\FakeTransport;
use Botect\Verdict;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/botect-test-'.bin2hex(random_bytes(8));
});
afterEach(function (): void {
    if (is_dir($this->directory)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->directory);
    }
});

test('spool is bounded, idempotent, and persists until explicitly drained', function (): void {
    $spool = new FileSpool($this->directory, capacity: 1);
    $delivery = Delivery::make(Operation::AssertLoggedIn, 'sess_test');
    expect($spool->dispatch($delivery))->toBeTrue()->and($spool->dispatch($delivery))->toBeTrue()
        ->and($spool->dispatch(Delivery::make(Operation::AssertLoggedIn, 'sess_other')))->toBeFalse();
    $seen = [];
    $result = (new FileSpool($this->directory))->flush(function (Delivery $item) use (&$seen): void {
        $seen[] = $item;
    });
    expect($result->sent)->toBe(1)->and($seen[0]->id)->toBe($delivery->id)->and(glob($this->directory.'/*.json'))->toBe([]);
});

test('transient failures back off with the same delivery ID and permanent failures are discarded', function (): void {
    $spool = new FileSpool($this->directory);
    $delivery = Delivery::make(Operation::AssertLoggedIn, 'sess_test');
    $spool->dispatch($delivery);
    $result = $spool->flush(fn () => throw new DeliveryException(true, 503));
    $stored = json_decode(file_get_contents($this->directory.'/'.$delivery->id.'.json'), true);
    expect($result->retried)->toBe(1)->and($stored['delivery']['id'])->toBe($delivery->id)
        ->and($stored['attempts'])->toBe(1)->and($stored['available_at'])->toBeGreaterThan(time());
    expect($spool->flush(fn () => throw new RuntimeException('Must not run'))->failed)->toBe(0);
    $stored['available_at'] = time() - 1;
    file_put_contents($this->directory.'/'.$delivery->id.'.json', json_encode($stored));
    expect($spool->flush(fn () => throw new DeliveryException(false, 422))->failed)->toBe(1)
        ->and(glob($this->directory.'/*.json'))->toBe([]);
});

test('worker locking prevents concurrent duplicate delivery', function (): void {
    $spool = new FileSpool($this->directory);
    $spool->dispatch(Delivery::make(Operation::AssertLoggedIn, 'sess_test'));
    $lock = fopen($this->directory.'/.worker.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        expect($spool->flush(fn () => throw new RuntimeException('Must not run'))->sent)->toBe(0);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

test('spool enqueue fails open when the filesystem is unavailable', function (): void {
    file_put_contents($this->directory, 'not a directory');
    expect((new FileSpool($this->directory))->dispatch(Delivery::make(Operation::AssertLoggedIn, 'sess_test')))->toBeFalse();
    unlink($this->directory);
});

test('cache does not enforce expired evidence and invalidation persists across instances', function (): void {
    $cache = new FileVerdictCache($this->directory);
    $cache->put('key', new Verdict('definite', 1, 'block'), -1);
    expect($cache->get('key'))->toBeNull();
    $cache->invalidate('sess_test');
    expect((new FileVerdictCache($this->directory))->generation('sess_test'))->toBe($cache->generation('sess_test'))->not->toBe('0');
});

test('spooled legacy and new event payloads survive reconstruction, retries, and delivery unchanged', function (bool $legacy): void {
    $event = ['request_id' => 'e1', 'type' => 'page', 'payload' => []];
    if ($legacy) {
        $event['received_at'] = '2026-01-01T00:00:00.123Z';
    } else {
        $event['sent_at'] = '2026-01-01T00:00:00.123Z';
        $event['relayed_at'] = '2026-10-25T12:00:00.123456Z';
    }
    $data = ['operation' => 'forward_events', 'session_token' => 'sess_test', 'payload' => [
        'schema_version' => 1, 'page_id' => str_repeat('c', 32), 'session_token' => 'sess_test', 'events' => [$event],
    ], 'id' => str_repeat('b', 64), 'created_at' => time() - 2];
    $delivery = Delivery::fromArray($data);
    $spool = new FileSpool($this->directory);
    expect($delivery->toArray())->toBe($data)->and($spool->dispatch($delivery))->toBeTrue();
    $transport = new FakeTransport;
    $transport->result = new Response(503, '');
    $sdk = new Botect(new Configuration('pk_test', 'sk_secret', serverIngestEnabled: true), $spool, new ArrayVerdictCache, $transport);
    expect($spool->flush($sdk->deliver(...))->retried)->toBe(1);
    $path = $this->directory.'/'.$delivery->id.'.json';
    $stored = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    expect($stored['delivery'])->toBe($data);
    $stored['available_at'] = time() - 1;
    file_put_contents($path, json_encode($stored, JSON_THROW_ON_ERROR));
    $transport->result = new Response(204, '');
    expect((new FileSpool($this->directory))->flush($sdk->deliver(...))->sent)->toBe(1)
        ->and($transport->requests)->toHaveCount(2);
    foreach ($transport->requests as $request) {
        expect(json_decode($request['body'], true, 32, JSON_THROW_ON_ERROR))->toBe($data['payload'])
            ->and($request['headers']['Idempotency-Key'])->toBe($data['id']);
    }
})->with(['v0.1.7' => true, 'stamped' => false]);
