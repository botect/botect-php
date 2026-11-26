<?php

declare(strict_types=1);

use Botect\ChallengeList;

require_once __DIR__.'/../ChallengeFixtures.php';

test('mixed crawler entries retain raw string ordering and equivalent host spellings across reuse', function (): void {
    $cidrs = ['102.212.60.78', '102.212.60.78/32', '2001:4860::8888', '5.39.1.0/24', '8.8.8.8'];
    $full = ChallengeList::fromArray(sdkChallengeListData(['crawler_cidrs' => $cidrs], 1000), now: 1000);
    $data = sdkChallengeListData(['crawler_catalogue' => 'unchanged'], 1010);
    unset($data['crawler_cidrs']);
    $reused = ChallengeList::fromArray($data, $full, 1010);
    expect($full->toArray()['crawler_cidrs'])->toBe($cidrs)
        ->and($reused->toArray()['crawler_cidrs'])->toBe($cidrs)
        ->and($reused->exempt('102.212.60.78'))->toBeTrue()
        ->and($reused->exempt('102.212.60.79'))->toBeFalse()
        ->and($reused->exempt('2001:4860::8888'))->toBeTrue()
        ->and($reused->exempt('2001:4860::8889'))->toBeFalse();
    foreach ([array_reverse($cidrs), [...$cidrs, '8.8.8.8']] as $invalid) {
        expect(fn () => ChallengeList::fromArray(sdkChallengeListData(['crawler_cidrs' => $invalid], 1000), now: 1000))->toThrow(UnexpectedValueException::class);
    }
});

test('snapshots preserve absolute expiry across catalogue reuse and allow a short source lifetime', function (): void {
    $full = ChallengeList::fromArray(sdkChallengeListData(['crawler_cidrs' => ['8.8.8.0/24'], 'expires_at' => 1100], 1000), now: 1000);
    $data = sdkChallengeListData(['crawler_catalogue' => 'unchanged'], 1010);
    unset($data['crawler_cidrs']);
    $reused = ChallengeList::fromArray($data, $full, 1010);
    expect($full->usable(1100))->toBeFalse()->and($full->toArray()['expires_at'])->toBe(1100)
        ->and($reused->exempt('8.8.8.1'))->toBeTrue()->and($reused->toArray()['expires_at'])->toBe(2810);
});

test('snapshot required fields and types are strict', function (string $key, mixed $value): void {
    expect(fn () => ChallengeList::fromArray(sdkChallengeListData([$key => $value], 1000), now: 1000))->toThrow(Exception::class);
})->with([
    ['schema_version', 2], ['schema_version', '1'], ['enabled', 1], ['status', 'unknown'], ['generated_at', '1000'],
    ['generated_at', 1031], ['refresh_after', 1301], ['expires_at', 2801], ['expires_at', 999], ['source_run_id', null],
    ['source_window_end', '1000'], ['source_window_end', 1001], ['header_shapes', ['bad']], ['header_shapes', ['b' => str_repeat('a', 32)]],
    ['header_shapes', [str_repeat('b', 32), str_repeat('a', 32)]], ['header_shapes', [str_repeat('a', 32), str_repeat('a', 32)]],
    ['crawler_directory_version', 42], ['crawler_catalogue', 'partial'], ['crawler_cidrs', ['8.8.8.8/33']],
    ['crawler_cidrs', ['::/129']], ['crawler_cidrs', ['nonsense']], ['crawler_cidrs', [123]],
    ['trusted_ranges', [['start' => str_repeat('f', 32), 'end' => str_repeat('0', 32), 'expires_at' => null]]],
    ['trusted_ranges', [['start' => str_repeat('A', 32), 'end' => str_repeat('f', 32), 'expires_at' => null]]],
    ['trusted_ranges', [['start' => str_repeat('0', 32), 'end' => str_repeat('f', 32), 'expires_at' => '1000']]],
]);

test('omitting any required field refuses the complete snapshot', function (string $key): void {
    $data = sdkChallengeListData([], 1000);
    unset($data[$key]);
    expect(fn () => ChallengeList::fromArray($data, now: 1000))->toThrow(Exception::class);
})->with(array_keys(sdkChallengeListData()));

test('unchanged catalogue requires the exact retained version', function (): void {
    $data = sdkChallengeListData(['crawler_catalogue' => 'unchanged'], 1000);
    unset($data['crawler_cidrs']);
    expect(fn () => ChallengeList::fromArray($data, now: 1000))->toThrow(Exception::class);
    $previous = ChallengeList::fromArray(sdkChallengeListData(['crawler_directory_version' => '41'], 1000), now: 1000);
    expect(fn () => ChallengeList::fromArray($data, $previous, 1000))->toThrow(Exception::class);
});

test('disabled snapshots are valid inert immediate expiries and ready-empty remains enabled', function (): void {
    $disabled = sdkChallengeListData(['enabled' => false, 'status' => 'disabled', 'expires_at' => 1000, 'source_run_id' => null, 'source_window_end' => null, 'header_shapes' => []], 1000);
    expect(ChallengeList::fromArray($disabled, now: 1000)->usable(1000))->toBeFalse()
        ->and(ChallengeList::fromArray(sdkChallengeListData(['header_shapes' => []], 1000), now: 1000)->usable(1000))->toBeTrue();
});
