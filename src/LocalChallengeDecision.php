<?php

declare(strict_types=1);

namespace Botect;

final readonly class LocalChallengeDecision
{
    public function __construct(public string $action = 'allow', public ?string $reason = null, public ?string $shape = null) {}
}
