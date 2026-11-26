<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\ClientIp;
use Botect\Support\ChallengeTokens;

final readonly class VerifyChallengeAction
{
    public function __construct(private ChallengeTokens $tokens) {}

    /** Host must first enforce same-origin JSON POST and a 4 KiB body limit. */
    public function execute(array $proof, string $session, ClientIp $ip, string $userAgent, string $origin, ?int $now = null): ?array
    {
        if (array_diff(array_keys($proof), ['ticket', 'answer']) !== [] || ! is_string($proof['ticket'] ?? null)
            || ! is_string($proof['answer'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $proof['answer'])) {
            return null;
        }
        $ticket = $this->tokens->verifyTicket($proof['ticket'], $session, $ip, $userAgent, $origin, $now);
        if ($ticket === null || ! hash_equals(hash('sha256', 'botect-js-v1:'.$ticket['nonce']), $proof['answer'])) {
            return null;
        }

        return ['clearance' => $this->tokens->clearance($session, $ip, $userAgent, $origin, $now), 'source' => $ticket['source'], 'return_to' => $ticket['return_to']];
    }
}
