<?php

declare(strict_types=1);

namespace Botect\Contracts;

interface ChallengeRenderer
{
    /** HTML only. Keys: ticket, nonce, verification_path, return_to, csp_nonce. */
    public function render(array $context): string;
}
