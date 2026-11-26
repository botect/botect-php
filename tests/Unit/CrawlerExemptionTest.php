<?php

declare(strict_types=1);

use Botect\Actions\MatchChallengeListAction;
use Botect\ChallengeList;
use Botect\ClientIp;
use Botect\Configuration;
use Botect\Support\IpRangeSet;

require_once __DIR__.'/../ChallengeFixtures.php';

test('bare crawler addresses cover exactly one packed host', function (string $ip, string $prefix, string $before, string $after): void {
    $bounds = IpRangeSet::cidr($ip);
    $set = new IpRangeSet([$bounds]);
    expect($bounds)->toBe([IpRangeSet::address($ip), IpRangeSet::address($ip)])
        ->and($bounds)->toBe(IpRangeSet::cidr($ip.$prefix))
        ->and($set->contains($ip))->toBeTrue()
        ->and($set->contains($before))->toBeFalse()
        ->and($set->contains($after))->toBeFalse();
})->with([
    ['102.212.60.78', '/32', '102.212.60.77', '102.212.60.79'],
    ['2001:4860::8888', '/128', '2001:4860::8887', '2001:4860::8889'],
]);

test('packed CIDR intervals include both boundaries, overlaps and both address families', function (): void {
    $set = new IpRangeSet(array_map(IpRangeSet::cidr(...), ['8.8.8.0/24', '8.8.8.128/25', '2001:4860::/32']));
    foreach (['8.8.8.0', '8.8.8.255', '::ffff:8.8.8.3', '2001:4860::', '2001:4860:ffff:ffff:ffff:ffff:ffff:ffff'] as $ip) {
        expect($set->contains($ip))->toBeTrue();
    }
    foreach (['8.8.7.255', '8.8.9.0', '2001:4861::'] as $ip) {
        expect($set->contains($ip))->toBeFalse();
    }
    expect(IpRangeSet::networkPrefix('8.8.8.12'))->toBe('8.8.8.0/24')
        ->and(IpRangeSet::networkPrefix('::ffff:8.8.8.12'))->toBe('8.8.8.0/24')
        ->and(IpRangeSet::networkPrefix('2001:4860:1234:5678::abcd'))->toBe('2001:4860:1234:5678::/64');
});

test('trusted entries cease exempting at their own expiry', function (): void {
    $snapshot = ChallengeList::fromArray(sdkChallengeListData(['trusted_ranges' => [
        ['start' => bin2hex(IpRangeSet::address('8.8.8.0')), 'end' => bin2hex(IpRangeSet::address('8.8.8.255')), 'expires_at' => 1050],
    ]], 1000), now: 1000);
    expect($snapshot->exempt('8.8.8.0', 1049))->toBeTrue()->and($snapshot->exempt('8.8.8.255', 1049))->toBeTrue()
        ->and($snapshot->exempt('8.8.8.1', 1050))->toBeFalse();
});

test('list eligibility fails open for every uncertain request', function (string $method, ?ClientIp $ip, ?bool $loggedIn, bool $html, bool $excluded): void {
    $action = new MatchChallengeListAction(new Configuration('pk_test', 'sk_secret', serverIngestEnabled: true, localChallengesEnabled: true));
    expect($action->execute(ChallengeList::fromArray(sdkChallengeListData()), $method, ['host', 'accept', 'user-agent'], $ip, $loggedIn, $html, $excluded)->action)->toBe('allow');
})->with([
    ['HEAD', new ClientIp('8.8.8.8', 'request'), false, true, false],
    ['POST', new ClientIp('8.8.8.8', 'request'), false, true, false],
    ['GET', null, false, true, false], ['GET', new ClientIp('8.8.8.8', 'request', true), false, true, false],
    ['GET', new ClientIp('127.0.0.1', 'resolver'), false, true, false], ['GET', new ClientIp('garbage', 'resolver'), false, true, false],
    ['GET', new ClientIp('8.8.8.8', 'request'), null, true, false], ['GET', new ClientIp('8.8.8.8', 'request'), true, true, false],
    ['GET', new ClientIp('8.8.8.8', 'request'), false, false, false], ['GET', new ClientIp('8.8.8.8', 'request'), false, true, true],
]);

test('shape matching applies the selected action and crawler exemption', function (string $choice): void {
    $action = new MatchChallengeListAction(new Configuration('pk_test', 'sk_secret', serverIngestEnabled: true, localChallengesEnabled: true, localChallengeAction: $choice));
    $snapshot = ChallengeList::fromArray(sdkChallengeListData(['crawler_cidrs' => ['8.8.8.0/24']]));
    $args = ['GET', ['host', 'accept', 'user-agent']];
    $decision = $action->execute($snapshot, ...[...$args, new ClientIp('9.9.9.9', 'request'), false, true, false]);
    expect($decision->action)->toBe($choice)->and($decision->reason)->toBe('header_shape')->and($decision->shape)->toBe('c0d7f5f1a627cd7a2a285ebaf4a1ad3c')
        ->and($action->execute($snapshot, ...[...$args, new ClientIp('8.8.8.8', 'request'), false, true, false])->action)->toBe('allow');
})->with(['challenge', 'block']);

test('mapped private addresses cannot authorize the new operation', function (): void {
    expect(IpRangeSet::publicAddress('::ffff:127.0.0.1'))->toBeFalse()
        ->and(IpRangeSet::publicAddress('::ffff:10.0.0.1'))->toBeFalse()
        ->and(IpRangeSet::publicAddress('::ffff:8.8.8.8'))->toBeTrue();
});
