<?php

declare(strict_types=1);

use Botect\Actions\VerifyChallengeAction;
use Botect\Botect;
use Botect\ClientIp;
use Botect\Configuration;
use Botect\Support\ChallengePage;
use Botect\Support\ChallengeTokens;
use Botect\Testing\ArrayVerdictCache;
use Botect\Testing\FakeDispatcher;
use Botect\Tests\FakeTransport;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->config = new Configuration('pk_test', 'sk_secret', localChallengesEnabled: true);
    $this->sdk = new Botect($this->config, new FakeDispatcher, new ArrayVerdictCache, new FakeTransport);
    $this->page = $this->sdk->page(loggedIn: false);
    $this->tokens = new ChallengeTokens($this->config);
    $this->ip = new ClientIp('8.8.8.8', 'request');
});

test('challenge proof yields a purpose-separated network-bound clearance with verified source', function (string $source): void {
    $issued = $this->tokens->issue($this->page, $this->ip, 'Browser', 'https://customer.test', '/search?q=hello', $source, 1000);
    $verified = (new VerifyChallengeAction($this->tokens))->execute(['ticket' => $issued['ticket'], 'answer' => hash('sha256', 'botect-js-v1:'.$issued['nonce'])], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1001);
    expect($verified['source'])->toBe($source)->and($verified['return_to'])->toBe('/search?q=hello')
        ->and($this->tokens->hasClearance($verified['clearance'], $this->page->sessionToken, new ClientIp('8.8.8.254', 'request'), 'Browser', 'https://customer.test', 1002))->toBeTrue()
        ->and($this->tokens->hasClearance($verified['clearance'], $this->page->sessionToken, new ClientIp('8.8.9.1', 'request'), 'Browser', 'https://customer.test', 1002))->toBeFalse()
        ->and($this->tokens->hasClearance($verified['clearance'], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 2801))->toBeFalse()
        ->and($this->tokens->verifyTicket($verified['clearance'], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1002))->toBeNull();
})->with(['crowd', 'verdict']);

test('tickets reject expiry, tampering and each changed binding', function (): void {
    $issued = $this->tokens->issue($this->page, $this->ip, 'Browser', 'https://customer.test', '/', now: 1000);
    foreach ([
        [$issued['ticket'].'x', $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1001],
        [$issued['ticket'], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1120],
        [$issued['ticket'], $this->page->sessionToken, new ClientIp('8.8.8.9', 'request'), 'Browser', 'https://customer.test', 1001],
        [$issued['ticket'], 'sess_other', $this->ip, 'Browser', 'https://customer.test', 1001],
        [$issued['ticket'], $this->page->sessionToken, $this->ip, 'Other', 'https://customer.test', 1001],
        [$issued['ticket'], $this->page->sessionToken, $this->ip, 'Browser', 'https://other.test', 1001],
    ] as $args) {
        expect($this->tokens->verifyTicket(...$args))->toBeNull();
    }
    foreach ([new Configuration('pk_other', 'sk_secret'), new Configuration('pk_test', 'sk_rotated'), new Configuration('pk_test', 'sk_secret', apiUrl: 'https://other.test/v1')] as $config) {
        expect((new ChallengeTokens($config))->verifyTicket($issued['ticket'], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1001))->toBeNull();
    }
});

test('invalid answers and unbounded proof shapes never yield clearance', function (mixed $answer): void {
    $issued = $this->tokens->issue($this->page, $this->ip, 'Browser', 'https://customer.test', '/', now: 1000);
    expect((new VerifyChallengeAction($this->tokens))->execute(['ticket' => $issued['ticket'], 'answer' => $answer], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1001))->toBeNull();
})->with([null, '', str_repeat('a', 64), str_repeat('A', 64), str_repeat('a', 65), 123]);

test('return targets refuse external and browser-ambiguous navigation', function (string $target): void {
    expect(fn () => ChallengeTokens::returnTarget($target))->toThrow(InvalidArgumentException::class);
})->with(['https://evil.test', '//evil.test', '/\\evil.test', '/%2fexample.com', '/a%0ab', '/a#fragment', '', '/'.str_repeat('a', 2048)]);

test('IPv6 clearance binds only the configured network and the renderer escapes context', function (): void {
    $ip = new ClientIp('2001:4860:1234:5678::1', 'request');
    $cookie = $this->tokens->clearance($this->page->sessionToken, $ip, 'Browser', 'https://customer.test', 1000);
    expect($this->tokens->hasClearance($cookie, $this->page->sessionToken, new ClientIp('2001:4860:1234:5678::ffff', 'request'), 'Browser', 'https://customer.test', 1001))->toBeTrue()
        ->and($this->tokens->hasClearance($cookie, $this->page->sessionToken, new ClientIp('2001:4860:1234:5679::1', 'request'), 'Browser', 'https://customer.test', 1001))->toBeFalse();
    $html = (new ChallengePage)->render(['ticket' => '</script><script>bad</script>', 'nonce' => 'nonce', 'verification_path' => '/_botect/challenge', 'return_to' => '/', 'csp_nonce' => 'safe']);
    expect($html)->toContain('nonce="safe"', 'SHA-256', 'botect-js-v1:', 'credentials:"same-origin"')->not->toContain('</script><script>bad');
});

test('configuration validates action and preserves all local fields when retimed', function (): void {
    expect(fn () => new Configuration('pk_test', localChallengeAction: 'deny'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Configuration('pk_test', clearanceSeconds: 59))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Configuration('pk_test', clearanceSeconds: 3601))->toThrow(InvalidArgumentException::class);
    $copy = (new Configuration('pk_test', 'sk_secret', localChallengesEnabled: true, localChallengeAction: 'block', challengePath: '/verify', clearanceSeconds: 60))->withTimeouts(300, 900);
    expect([$copy->localChallengesEnabled, $copy->localChallengeAction, $copy->challengePath, $copy->clearanceSeconds])->toBe([true, 'block', '/verify', 60]);
});

test('challenge logging exposes only permitted fields and ignores callback failure', function (): void {
    $records = [];
    $sdk = new Botect($this->config, new FakeDispatcher, new ArrayVerdictCache, new FakeTransport, challengeLogger: function ($event, $context) use (&$records): void {
        $records[] = [$event, $context];
        throw new RuntimeException('Logger down');
    });
    $sdk->logChallenge('challenge_issued', 'crowd', $this->ip, 'header_shape', str_repeat('a', 32));
    $sdk->logChallenge('clearance_issued', 'verdict', $this->ip);
    expect($records)->toBe([
        ['challenge_issued', ['source' => 'crowd', 'network_prefix' => '8.8.8.0/24', 'reason' => 'header_shape', 'shape' => str_repeat('a', 32)]],
        ['clearance_issued', ['source' => 'verdict', 'network_prefix' => '8.8.8.0/24']],
    ]);
});

test('return targets retain valid percent-encoded spaces in paths and queries', function (string $target): void {
    expect(ChallengeTokens::returnTarget($target))->toBe($target);
    $issued = $this->tokens->issue($this->page, $this->ip, 'Browser', 'https://customer.test', $target, now: 1000);
    expect($this->tokens->verifyTicket($issued['ticket'], $this->page->sessionToken, $this->ip, 'Browser', 'https://customer.test', 1001)['return_to'])->toBe($target);
})->with(['/hello%20world', '/search?q=hello%20world', '/hello%20world?q=more%20words']);

test('return targets still reject raw whitespace and decoded controls', function (string $target): void {
    expect(fn () => ChallengeTokens::returnTarget($target))->toThrow(InvalidArgumentException::class);
})->with(['/hello world', '/search?q=hello world', "/tab\there", '/%09tab', '/%00zero', '/%5cevil', '/%2fexternal.test']);

test('bundled browser script preserves proof with fallback and exposes recoverable errors', function (string $mode): void {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('Node is required to execute the bundled JavaScript regression.');
    }
    $nonce = str_repeat('A', 43);
    $html = (new ChallengePage)->render(['ticket' => 'signed-ticket', 'nonce' => $nonce, 'verification_path' => '/_botect/challenge', 'return_to' => '/search?q=a%20b', 'csp_nonce' => 'safe']);
    preg_match('/<script nonce="safe">(.*?)<\/script>/s', $html, $matches);
    $harness = <<<'JS'
const vm=require('node:vm');
const script=process.argv[1],mode=process.argv[2];
let proof=null,target=null,reloads=0,click=null;
const status={textContent:''},retry={hidden:true,addEventListener:(event,callback)=>{click=callback;}};
const sandbox={document:{getElementById:id=>id==='botect-status'?status:retry},location:{assign:value=>{target=value;},reload:()=>{reloads++;}}};
if(mode!=='missing-api')sandbox.fetch=async(url,options)=>{proof=JSON.parse(options.body);if(mode==='network')throw Error('offline');return {status:mode==='rejected'?403:204};};
if(mode==='subtle-fails'){sandbox.crypto={subtle:{digest:async()=>{throw Error('unavailable');}}};sandbox.TextEncoder=TextEncoder;}
Promise.resolve(vm.runInNewContext(script,sandbox)).then(()=>{if(!retry.hidden)click();process.stdout.write(JSON.stringify({proof,target,message:status.textContent,reloads}));}).catch(e=>{process.stderr.write(String(e));process.exitCode=1;});
JS;
    $process = new Process([$node, '-e', $harness, $matches[1], $mode]);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
    if (in_array($mode, ['fallback', 'subtle-fails'], true)) {
        expect($result['proof'])->toBe(['ticket' => 'signed-ticket', 'answer' => hash('sha256', 'botect-js-v1:'.$nonce)])
            ->and($result['target'])->toBe('/search?q=a%20b')->and($result['reloads'])->toBe(0);
    } else {
        expect($result['target'])->toBeNull()->and($result['message'])->toContain('reload')->and($result['reloads'])->toBe(1);
        expect($result['message'])->toContain(match ($mode) {
            'network' => 'network', 'rejected' => 'rejected or expired', default => 'current browser'
        });
    }
    expect($html)->not->toContain('onclick=', 'http://', 'https://');
})->with(['fallback', 'subtle-fails', 'missing-api', 'network', 'rejected']);
