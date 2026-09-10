<?php

declare(strict_types=1);

use Botect\Botect;
use Botect\Configuration;
use Botect\Delivery;
use Botect\Delivery\DeferredDispatcher;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Botect\Operation;
use Botect\Storage\FileVerdictCache;
use Botect\Storage\MemoryVerdictCache;
use Botect\Tests\FakeTransport;
use Botect\Verdict;
use Symfony\Component\Process\Process;

function pendingAssertion(string $session = 'sess_test'): Delivery
{
    return Delivery::make(Operation::AssertLoggedIn, $session);
}

test('deferred buffers deduplicate and send only once when drained', function (): void {
    $sent = [];
    $dispatcher = new DeferredDispatcher(function (Delivery $delivery) use (&$sent): void {
        $sent[] = $delivery;
    });
    $delivery = pendingAssertion();
    expect($dispatcher->dispatch($delivery))->toBeTrue()->and($dispatcher->dispatch($delivery))->toBeTrue()->and($sent)->toBe([]);
    expect($dispatcher->drain()->sent)->toBe(1)->and($sent)->toHaveCount(1)->and($dispatcher->drain()->sent)->toBe(0);
    $dispatcher->dispatch(pendingAssertion('sess_next_request'));
    expect($dispatcher->drain()->sent)->toBe(1)->and($sent)->toHaveCount(2);
});

test('retryable and unexpected failures are discarded without escaping or retrying', function (): void {
    $calls = 0;
    $dispatcher = new DeferredDispatcher(function () use (&$calls): void {
        $calls++;
        throw $calls === 1 ? new DeliveryException(true, 503) : new RuntimeException('Network failed');
    });
    $dispatcher->dispatch(pendingAssertion());
    $dispatcher->dispatch(pendingAssertion('sess_second'));
    $result = $dispatcher->drain();
    expect($result->failed)->toBe(2)->and($result->retried)->toBe(0)->and($result->sent)->toBe(0);
    $dispatcher->drain();
    expect($calls)->toBe(2);
});

test('buffer limits reject excess deliveries and reset for subsequent requests', function (): void {
    $dispatcher = new DeferredDispatcher(static function (): void {}, capacity: 1);
    expect($dispatcher->dispatch(pendingAssertion()))->toBeTrue()
        ->and($dispatcher->dispatch(pendingAssertion('sess_second')))->toBeFalse();
    $dispatcher->drain();
    expect($dispatcher->dispatch(pendingAssertion('sess_second')))->toBeTrue();
    $tiny = new DeferredDispatcher(static function (): void {}, maxBytes: 1);
    expect($tiny->dispatch(pendingAssertion()))->toBeFalse();
});

test('drain budget discards remaining work after a slow attempt', function (): void {
    $calls = 0;
    $dispatcher = new DeferredDispatcher(function () use (&$calls): void {
        $calls++;
        usleep(10000);
    }, budgetMs: 1);
    $dispatcher->dispatch(pendingAssertion());
    $dispatcher->dispatch(pendingAssertion('sess_second'));
    $result = $dispatcher->drain();
    expect($calls)->toBe(1)->and($result->sent)->toBe(1)->and($result->failed)->toBe(1)->and($result->retried)->toBe(0);
    expect($dispatcher->drain()->sent)->toBe(0);
});

test('default plain PHP needs no storage and separate SDK instances do not share deliveries', function (): void {
    $transport = new FakeTransport;
    $transport->result = new Response(200, '{"logged_in":true,"asserted_at":"2026-09-09T00:00:00Z"}');
    $first = Botect::create(new Configuration('pk_first', 'sk_first'), transport: $transport);
    $second = Botect::create(new Configuration('pk_second', 'sk_second'), transport: $transport);
    expect($first->loggedIn('sess_first'))->toBeTrue()->and($second->loggedIn('sess_second'))->toBeTrue()
        ->and($transport->requests)->toBe([]);
    expect($first->sendPending()->sent)->toBe(1)->and($transport->requests)->toHaveCount(1);
    expect($second->sendPending()->sent)->toBe(1)->and($transport->requests)->toHaveCount(2);
    expect(fn () => $first->flush())->toThrow(LogicException::class, 'requires spool');
    expect(fn () => Botect::create(new Configuration('pk_test'), delivery: 'spool'))->toThrow(InvalidArgumentException::class);
});

test('explicit verdict lookup returns evidence immediately without scheduling a refresh', function (): void {
    $transport = new FakeTransport;
    $transport->result = new Response(200, '{"verdict":"definite","score":1,"action":"block","detection_ids":[12],"reason":"Automation"}');
    $sdk = Botect::create(new Configuration('pk_test', 'sk_secret'), transport: $transport);
    expect($sdk->lookupVerdict('sess_test', ['path' => '/checkout'])->action)->toBe('block')
        ->and($transport->requests)->toHaveCount(1)
        ->and($sdk->sendPending()->sent)->toBe(0);
    expect($sdk->lookupVerdict('sess_test', ['secret' => 'value'])->available())->toBeFalse()
        ->and($sdk->lookupVerdict('../bad')->available())->toBeFalse()->and($transport->requests)->toHaveCount(1);
    $transport->result = new Response(503, 'unavailable');
    expect($sdk->lookupVerdict('sess_test')->action)->toBe('allow')->and($transport->requests)->toHaveCount(2);
});

test('cached reads remain deferred and can reuse an explicitly shared cache', function (): void {
    $cache = new MemoryVerdictCache;
    $transport = new FakeTransport;
    $transport->result = new Response(200, '{"verdict":"likely_human","score":90,"action":"allow","detection_ids":[],"reason":"Human"}');
    $sdk = Botect::create(new Configuration('pk_test', 'sk_secret'), transport: $transport, cache: $cache);
    expect($sdk->verdict('sess_test')->available())->toBeFalse()->and($transport->requests)->toBe([]);
    expect($sdk->sendPending()->sent)->toBe(1);
    $next = Botect::create(new Configuration('pk_test', 'sk_secret'), transport: $transport, cache: $cache);
    expect($next->verdict('sess_test')->score)->toBe(90)->and($transport->requests)->toHaveCount(1);
});

test('plain PHP shutdown fallback preserves application shutdown output and delivers last', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'botect-shutdown-');
    $autoload = var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true);
    file_put_contents($script, '<?php require '.$autoload.';
        $dispatcher = new Botect\\Delivery\\DeferredDispatcher(function () { echo "SENT"; });
        $dispatcher->registerShutdown();
        $dispatcher->dispatch(Botect\\Delivery::make(Botect\\Operation::AssertLoggedIn, "sess_test"));
        register_shutdown_function(function () { echo "APP-SHUTDOWN|"; });
        echo "RESPONSE|";
    ');
    try {
        $process = new Process([PHP_BINARY, $script]);
        $process->mustRun();
        expect($process->getOutput())->toBe('RESPONSE|APP-SHUTDOWN|SENT');
    } finally {
        unlink($script);
    }
});

test('deferred file verdict caching offers explicit expiry cleanup', function (): void {
    $directory = sys_get_temp_dir().'/botect-prune-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $cache = new FileVerdictCache($directory);
    $cache->put('expired', new Verdict('likely_human', 90, 'allow'), -1);
    $cache->put('live', new Verdict('likely_human', 90, 'allow'), 30);
    try {
        $sdk = Botect::create(new Configuration('pk_test', 'sk_secret'), cache: $cache);
        expect($sdk->pruneVerdictCache())->toBe(1)->and($cache->get('live')->score)->toBe(90);
        expect(Botect::create(new Configuration('pk_memory'))->pruneVerdictCache())->toBe(0);
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
