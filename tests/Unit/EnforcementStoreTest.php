<?php

declare(strict_types=1);

use Botect\Actions\RefreshChallengeListAction;
use Botect\ApiClient;
use Botect\Botect;
use Botect\ChallengeList;
use Botect\ClientIp;
use Botect\Configuration;
use Botect\Contracts\EnforcementStore;
use Botect\Contracts\HttpTransport;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Botect\Storage\FileEnforcementStore;
use Botect\Storage\MemoryEnforcementStore;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../ChallengeFixtures.php';

beforeEach(function (): void {
    $this->configuration = new Configuration('pk_test', 'sk_secret', serverIngestEnabled: true, localChallengesEnabled: true);
    $this->store = new MemoryEnforcementStore;
    $this->refresh = new RefreshChallengeListAction($this->configuration, $this->store);
    $this->transport = new FakeTransport;
    $this->client = new ApiClient($this->configuration, $this->transport);
});

test('refresh leases are exclusive and refuse stale scope or stale owners', function (): void {
    $delivery = $this->refresh->delivery();
    expect($delivery->sessionToken)->toBe('sdk_list_v1')->and($delivery->payload['store_scope'])->toBe($this->configuration->enforcementScope())
        ->and($this->refresh->delivery())->toBeNull();
    $wrong = new Delivery($delivery->operation, $delivery->sessionToken, array_replace($delivery->payload, ['store_scope' => 'other']), $delivery->id, $delivery->createdAt);
    $this->refresh->execute($wrong, $this->client);
    $this->store->update(function (array &$state): void {
        $state['scopes'][$this->configuration->enforcementScope()]['lease_owner'] = 'new-owner';
    });
    $this->refresh->execute($delivery, $this->client);
    expect($this->transport->requests)->toBe([]);
});

test('success publishes one complete snapshot and reuses only its exact catalogue', function (): void {
    $this->transport->result = new Response(200, json_encode(sdkChallengeListData(['crawler_cidrs' => ['8.8.8.0/24']])));
    $this->refresh->execute($this->refresh->delivery(), $this->client);
    $data = sdkChallengeListData(['crawler_catalogue' => 'unchanged']);
    unset($data['crawler_cidrs']);
    $this->transport->result = new Response(200, json_encode($data));
    $this->refresh->execute($this->refresh->delivery(), $this->client);
    expect($this->transport->requests[1]['url'])->toEndWith('?crawler_directory_version=42')
        ->and($this->refresh->snapshot()->exempt('8.8.8.1'))->toBeTrue()
        ->and($this->transport->requests[0]['url'])->not->toContain('sdk_list_v1');
});

test('a mixed crawler catalogue passes HTTP validation publication stored hydration and reuse', function (): void {
    $cidrs = json_decode(file_get_contents(__DIR__.'/../Fixtures/mixed-crawler-catalogue.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->transport->result = new Response(200, json_encode(sdkChallengeListData(['crawler_cidrs' => $cidrs]), JSON_THROW_ON_ERROR));
    $this->refresh->execute($this->refresh->delivery(), $this->client);
    $reader = new RefreshChallengeListAction($this->configuration, $this->store);
    $snapshot = $reader->snapshot();
    expect($snapshot)->toBeInstanceOf(ChallengeList::class);
    foreach (['102.212.60.78', '2001:4860::8888', '2606:4700:4700::1234', '5.39.1.224'] as $ip) {
        expect($snapshot->exempt($ip))->toBeTrue();
    }
    foreach (['102.212.60.77', '102.212.60.79', '2001:4860::8887', '2001:4860::8889', '2606:4700:4700::1:0', '5.39.2.0'] as $ip) {
        expect($snapshot->exempt($ip))->toBeFalse();
    }
    expect($snapshot->toArray()['crawler_cidrs'])->toBe($cidrs);
    $data = sdkChallengeListData(['crawler_catalogue' => 'unchanged']);
    unset($data['crawler_cidrs']);
    $this->transport->result = new Response(200, json_encode($data, JSON_THROW_ON_ERROR));
    $reader->execute($reader->delivery(), $this->client);
    $reused = (new RefreshChallengeListAction($this->configuration, $this->store))->snapshot();
    expect($this->transport->requests[1]['url'])->toEndWith('?crawler_directory_version=42')
        ->and($reused->toArray()['crawler_cidrs'])->toBe($cidrs)
        ->and($reused->exempt('102.212.60.78'))->toBeTrue()
        ->and($reused->exempt('2001:4860::8888'))->toBeTrue()
        ->and($reused->exempt('2001:4860::8889'))->toBeFalse();
});

test('failure classes retain or invalidate evidence and early retries defer', function (int $status, string $body, bool $retain): void {
    $original = sdkChallengeListData();
    sdkSeedEnforcement($this->store, $this->configuration, $original);
    $delivery = $this->refresh->delivery();
    $this->transport->result = new Response($status, $body);
    try {
        $this->refresh->execute($delivery, $this->client);
    } catch (DeliveryException $exception) {
        expect($exception->retryable)->toBe($retain);
    }
    expect($this->refresh->snapshot()?->toArray())->toBe($retain ? $original : null);
    if ($retain) {
        try {
            $this->refresh->execute($delivery, $this->client);
            test()->fail('Expected backoff');
        } catch (DeliveryException $exception) {
            expect($exception->retryAfterSeconds)->toBeGreaterThanOrEqual(29);
        }
        expect($this->transport->requests)->toHaveCount(1);
    }
})->with([
    [408, '<html>timeout</html>', true], [425, '', true], [429, '', true], [500, 'invalid', true], [503, '', true],
    [401, '', false], [402, '', false], [403, '', false], [302, '', false], [204, '', false],
    [200, '<html>bad</html>', false], [200, '{"schema_version":2}', false],
]);

test('disabled and missing-catalogue responses remove old enforcement immediately', function (): void {
    sdkSeedEnforcement($this->store, $this->configuration, sdkChallengeListData());
    $this->transport->result = new Response(200, json_encode(sdkChallengeListData(['enabled' => false, 'status' => 'disabled', 'expires_at' => time(), 'source_run_id' => null, 'source_window_end' => null, 'header_shapes' => []])));
    $this->refresh->execute($this->refresh->delivery(), $this->client);
    expect($this->refresh->snapshot())->toBeNull();
    $this->store->update(function (array &$state): void {
        $state['scopes'][$this->configuration->enforcementScope()]['next_attempt_at'] = 0;
    });
    $data = sdkChallengeListData(['crawler_catalogue' => 'unchanged']);
    unset($data['crawler_cidrs']);
    $this->transport->result = new Response(200, json_encode($data));
    expect(fn () => $this->refresh->execute($this->refresh->delivery(), $this->client))->toThrow(DeliveryException::class)
        ->and($this->refresh->snapshot())->toBeNull();
});

test('a completion after a newer owner published cannot restore its evidence', function (): void {
    $store = $this->store;
    $config = $this->configuration;
    $transport = new class($store, $config) implements HttpTransport
    {
        public function __construct(private $store, private $config) {}

        public function send(string $method, string $url, array $headers, ?string $body): Response
        {
            $this->store->update(function (array &$state): void {
                $state['scopes'][$this->config->enforcementScope()] = ['lease_owner' => 'new', 'snapshot' => null];
            });

            return new Response(200, json_encode(sdkChallengeListData()));
        }
    };
    $this->refresh->execute($this->refresh->delivery(), new ApiClient($config, $transport));
    expect($this->refresh->snapshot())->toBeNull();
});

test('credential rotation never reuses catalogue or snapshots in the same adapter', function (): void {
    sdkSeedEnforcement($this->store, $this->configuration, sdkChallengeListData());
    $other = new RefreshChallengeListAction(new Configuration('pk_test', 'sk_rotated', localChallengesEnabled: true), $this->store);
    expect($other->snapshot())->toBeNull()->and($other->delivery())->not->toBeNull()
        ->and($this->configuration->namespace())->toBe((new Configuration('pk_test', 'sk_rotated'))->namespace());
});

test('memory-only lazy refresh does not dispatch and explicit refresh populates the instance', function (): void {
    $dispatcher = new FakeDispatcher;
    $sdk = new Botect($this->configuration, $dispatcher, new ArrayVerdictCache, $this->transport, $this->store);
    $args = ['GET', ['host', 'accept', 'user-agent'], new ClientIp('9.9.9.9', 'request'), false, 'Browser', true];
    expect($sdk->localChallenge(...$args)->action)->toBe('allow')->and($dispatcher->deliveries)->toBe([]);
    $this->transport->result = new Response(200, json_encode(sdkChallengeListData()));
    expect($sdk->refreshChallengeList())->toBeTrue()->and($sdk->localChallenge(...$args)->action)->toBe('challenge');
});

test('file store publishes atomically and permits readers while a mutation lock is held', function (): void {
    $directory = sys_get_temp_dir().'/botect-enforcement-'.bin2hex(random_bytes(6));
    $store = new FileEnforcementStore($directory);
    try {
        sdkSeedEnforcement($store, $this->configuration, sdkChallengeListData());
        expect((new FileEnforcementStore($directory))->read())->toBe($store->read())
            ->and(fileperms($directory) & 0777)->toBe(0700)->and(fileperms($directory.'/state.json') & 0777)->toBe(0600);
        $lock = fopen($directory.'/state.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            expect($store->read())->toBe((new FileEnforcementStore($directory))->read());
            expect(fn () => $store->update(static function (array &$state): void {}))->toThrow(RuntimeException::class);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } finally {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        } rmdir($directory);
    }
});

test('refused dispatch releases its lease and backs off five seconds', function (): void {
    $dispatcher = new FakeDispatcher;
    $dispatcher->accept = false;
    expect($this->refresh->dispatch($dispatcher))->toBeFalse();
    $state = $this->store->read()['scopes'][$this->configuration->enforcementScope()];
    expect($state['lease_owner'])->toBeNull()->and($state['next_attempt_at'])->toBeGreaterThanOrEqual(time() + 4);
});

test('expired enqueue leases are reacquired before fetching and expired completions cannot publish', function (): void {
    $delivery = $this->refresh->delivery();
    $this->store->update(function (array &$state): void {
        $state['scopes'][$this->configuration->enforcementScope()]['lease_expires_at'] = time() - 1;
    });
    $this->transport->result = new Response(200, json_encode(sdkChallengeListData()));
    $this->refresh->execute($delivery, $this->client);
    expect($this->refresh->snapshot()?->usable())->toBeTrue();
    $delivery = $this->refresh->delivery();
    $store = $this->store;
    $scope = $this->configuration->enforcementScope();
    $transport = new class($store, $scope) implements HttpTransport
    {
        public function __construct(private $store, private string $scope) {}

        public function send(string $method, string $url, array $headers, ?string $body): Response
        {
            $this->store->update(function (array &$state): void {
                $state['scopes'][$this->scope]['lease_expires_at'] = time() - 1;
                $state['scopes'][$this->scope]['snapshot'] = null;
            });

            return new Response(200, json_encode(sdkChallengeListData()));
        }
    };
    $this->refresh->execute($delivery, new ApiClient($this->configuration, $transport));
    expect($this->refresh->snapshot())->toBeNull();
});

test('connection failure retains evidence until its original expiry', function (): void {
    $snapshot = sdkChallengeListData();
    sdkSeedEnforcement($this->store, $this->configuration, $snapshot);
    $this->transport->result = new RuntimeException('Connection timeout');
    expect(fn () => $this->refresh->execute($this->refresh->delivery(), $this->client))->toThrow(DeliveryException::class);
    expect($this->refresh->snapshot()->toArray()['expires_at'])->toBe($snapshot['expires_at'])
        ->and($this->refresh->snapshot()->usable($snapshot['expires_at']))->toBeFalse();
});

test('the cheap path loads neither catalogue nor intervals before a shape hit', function (): void {
    $backing = new MemoryEnforcementStore;
    $store = new class($backing) implements EnforcementStore
    {
        public int $reads = 0;

        public int $catalogueReads = 0;

        public function __construct(private MemoryEnforcementStore $backing) {}

        public function read(): array
        {
            $this->reads++;

            return $this->backing->read();
        }

        public function readCatalogue(string $id): ?array
        {
            $this->catalogueReads++;

            return $this->backing->readCatalogue($id);
        }

        public function writeCatalogue(string $id, array $catalogue): void
        {
            $this->backing->writeCatalogue($id, $catalogue);
        }

        public function update(Closure $change): mixed
        {
            return $this->backing->update($change);
        }

        public function persistent(): bool
        {
            return false;
        }
    };
    sdkSeedEnforcement($store, $this->configuration, sdkChallengeListData(['crawler_cidrs' => ['8.8.8.0/24']]));
    $sdk = new Botect($this->configuration, new FakeDispatcher, new ArrayVerdictCache, $this->transport, $store);
    $ip = new ClientIp('9.9.9.9', 'request');
    expect($sdk->localChallenge('HEAD', ['host'], $ip, false, 'Browser', true)->action)->toBe('allow')->and($store->reads)->toBe(0);
    expect($sdk->localChallenge('GET', ['host'], $ip, true, 'Browser', true)->action)->toBe('allow')->and($store->reads)->toBe(0);
    expect($sdk->localChallenge('GET', ['host'], $ip, false, 'Browser', true)->action)->toBe('allow')->and($store->reads)->toBe(1)->and($store->catalogueReads)->toBe(0);
    // A second request-local SDK must also avoid the full catalogue on a miss.
    $next = new Botect($this->configuration, new FakeDispatcher, new ArrayVerdictCache, $this->transport, $store);
    expect($next->localChallenge('GET', ['host'], $ip, false, 'Browser', true)->action)->toBe('allow')->and($store->catalogueReads)->toBe(0);
    expect($next->localChallenge('GET', ['host', 'accept', 'user-agent'], $ip, false, 'Browser', true)->action)->toBe('challenge')->and($store->catalogueReads)->toBe(1);
    expect($store->read()['scopes'][$this->configuration->enforcementScope()]['snapshot'])->not->toHaveKeys(['crawler_cidrs', 'trusted_ranges']);
});

test('published packed exemptions are hydrated without CIDR validation or compilation', function (): void {
    $list = ChallengeList::fromArray(sdkChallengeListData(['crawler_cidrs' => ['8.8.8.0/24']]));
    $artifact = $list->catalogue();
    // The raw catalogue is only needed for refresh reuse; the read path uses packed intervals.
    $artifact['crawler_cidrs'] = ['not-a-cidr'];
    $loaded = ChallengeList::fromStored($list->metadata(str_repeat('a', 64)), fn () => $artifact);
    expect($loaded->hasShape('c0d7f5f1a627cd7a2a285ebaf4a1ad3c'))->toBeTrue()->and($loaded->exempt('8.8.8.1'))->toBeTrue();
});

test('valid disabled responses retain their absolute refresh time without enforcement evidence', function (): void {
    sdkSeedEnforcement($this->store, $this->configuration, sdkChallengeListData());
    $generated = time() - 10;
    $data = sdkChallengeListData(['enabled' => false, 'status' => 'disabled', 'expires_at' => $generated, 'source_run_id' => null, 'source_window_end' => null, 'header_shapes' => []], $generated);
    $this->transport->result = new Response(200, json_encode($data));
    $this->refresh->execute($this->refresh->delivery(), $this->client);
    $state = $this->store->read()['scopes'][$this->configuration->enforcementScope()];
    expect($state['snapshot'])->toBeNull()->and($state['next_attempt_at'])->toBe($generated + 300)->and($this->refresh->delivery())->toBeNull();
});

test('two concurrent file readers enforce the same snapshot without taking its mutation lock', function (): void {
    $directory = sys_get_temp_dir().'/botect-concurrent-'.bin2hex(random_bytes(6));
    $store = new FileEnforcementStore($directory);
    sdkSeedEnforcement($store, $this->configuration, sdkChallengeListData());
    $script = <<<'PHP_SCRIPT'
require $argv[1];
$config = new \Botect\Configuration('pk_test', 'sk_secret', serverIngestEnabled: true, localChallengesEnabled: true);
$sdk = new \Botect\Botect($config, new \Botect\Testing\FakeDispatcher, new \Botect\Testing\ArrayVerdictCache, new \Botect\Tests\FakeTransport, new \Botect\Storage\FileEnforcementStore($argv[2]));
for ($i = 0; $i < 100; $i++) {
    $decision = $sdk->localChallenge('GET', ['host', 'accept', 'user-agent'], new \Botect\ClientIp('9.9.9.9', 'request'), false, 'Browser', true);
    if ($decision->action !== 'challenge') { throw new \RuntimeException('Concurrent read failed open.'); }
}
echo '100 enforced';
PHP_SCRIPT;
    $command = [PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $directory];
    $first = new Process($command);
    $second = new Process($command);
    $lock = fopen($directory.'/state.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $first->start();
        $second->start();
        expect($first->wait())->toBe(0)->and($second->wait())->toBe(0)
            ->and($first->getOutput())->toBe('100 enforced')->and($second->getOutput())->toBe('100 enforced');
    } finally {
        $first->stop();
        $second->stop();
        flock($lock, LOCK_UN);
        fclose($lock);
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
