<?php

declare(strict_types=1);
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Operation;
use Botect\Storage\FileSpool;
use Botect\Storage\FileVerdictCache;
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
