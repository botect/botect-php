<?php

declare(strict_types=1);

use Botect\Verdict;

test('inconclusive verdicts preserve API fields and round trip without an available score', function (string $action): void {
    $data = ['verdict' => 'inconclusive', 'score' => 0, 'action' => $action, 'detection_ids' => [12, 34], 'reason' => 'Scored without reaching a verdict'];
    $verdict = Verdict::fromArray($data);

    expect($verdict->verdict)->toBe('inconclusive')->and($verdict->score)->toBe(0)
        ->and($verdict->action)->toBe($action)->and($verdict->detectionIds)->toBe([12, 34])
        ->and($verdict->reason)->toBe($data['reason'])->and($verdict->available())->toBeFalse()
        ->and($verdict->inconclusive())->toBeTrue()->and($verdict->toArray())->toBe($data);
})->with(['allow', 'block', 'challenge', 'log', 'delay']);

test('inconclusive verdicts reject non-zero scores and invalid fields', function (string $field, mixed $value): void {
    $data = ['verdict' => 'inconclusive', 'score' => 0, 'action' => 'allow', 'detection_ids' => [], 'reason' => 'No verdict'];
    $data[$field] = $value;

    expect(fn () => Verdict::fromArray($data))->toThrow(UnexpectedValueException::class, 'Invalid Botect verdict response.');
})->with([
    'positive score' => ['score', 1],
    'maximum score' => ['score', 99],
    'negative score' => ['score', -1],
    'string score' => ['score', '0'],
    'invalid action' => ['action', 'unknown'],
    'non-list detections' => ['detection_ids', ['id' => 12]],
    'non-integer detection' => ['detection_ids', ['12']],
    'invalid reason' => ['reason', null],
]);

test('other score-zero verdicts retain their existing availability and collapse behavior', function (string $band): void {
    $verdict = Verdict::fromArray(['verdict' => $band, 'score' => 0, 'action' => 'block', 'detection_ids' => [12], 'reason' => 'Evidence']);

    expect($verdict->inconclusive())->toBeFalse()->and($verdict->available())->toBe($band === 'verified');
    if ($band === 'verified') {
        expect($verdict->verdict)->toBe('verified')->and($verdict->action)->toBe('block');
    } else {
        expect($verdict->toArray())->toBe((new Verdict)->toArray());
    }
})->with(['not_computed', 'definite', 'confident_automated', 'likely_automated', 'likely_human', 'verified']);
