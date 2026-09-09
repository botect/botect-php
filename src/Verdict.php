<?php

declare(strict_types=1);

namespace Botect;

use UnexpectedValueException;

final readonly class Verdict
{
    /** @param list<int> $detectionIds */
    public function __construct(public string $verdict = 'not_computed', public int $score = 0, public string $action = 'allow', public array $detectionIds = [], public string $reason = 'Score unavailable; allowing by default.') {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! in_array($data['action'] ?? null, ['allow', 'block', 'challenge', 'log', 'delay'], true)
            || ! is_int($data['score'] ?? null) || $data['score'] < 0 || $data['score'] > 99
            || ! in_array($data['verdict'] ?? null, ['not_computed', 'definite', 'confident_automated', 'likely_automated', 'likely_human', 'verified'], true)
            || ! is_array($data['detection_ids'] ?? null) || ! array_is_list($data['detection_ids'])
            || count(array_filter($data['detection_ids'], 'is_int')) !== count($data['detection_ids'])
            || ! is_string($data['reason'] ?? null)) {
            throw new UnexpectedValueException('Invalid Botect verdict response.');
        }
        if ($data['verdict'] === 'not_computed' || ($data['score'] === 0 && $data['verdict'] !== 'verified')) {
            return new self;
        }

        return new self($data['verdict'], $data['score'], $data['action'], $data['detection_ids'], $data['reason']);
    }

    public function available(): bool
    {
        return $this->verdict !== 'not_computed' && ($this->score > 0 || $this->verdict === 'verified');
    }

    /** @return array{verdict:string, score:int, action:string, detection_ids:list<int>, reason:string} */
    public function toArray(): array
    {
        return ['verdict' => $this->verdict, 'score' => $this->score, 'action' => $this->action, 'detection_ids' => $this->detectionIds, 'reason' => $this->reason];
    }
}
