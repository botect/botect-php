<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\ChallengeList;
use Botect\ClientIp;
use Botect\Configuration;
use Botect\LocalChallengeDecision;
use Botect\Support\HeaderShape;
use Botect\Support\IpRangeSet;

final readonly class MatchChallengeListAction
{
    public function __construct(private Configuration $configuration) {}

    public function eligible(string $method, ?ClientIp $clientIp, ?bool $loggedIn, bool $htmlNavigation, bool $excluded): bool
    {
        return $this->configuration->localChallengesEnabled && $this->configuration->serverIngestEnabled
            && strtoupper($method) === 'GET' && $htmlNavigation && ! $excluded && $loggedIn === false
            && $clientIp !== null && ! $clientIp->inferred && IpRangeSet::publicAddress($clientIp->ip);
    }

    public function execute(?ChallengeList $snapshot, string $method, array $headerNames, ?ClientIp $clientIp, ?bool $loggedIn, bool $htmlNavigation, bool $excluded): LocalChallengeDecision
    {
        if (! $this->eligible($method, $clientIp, $loggedIn, $htmlNavigation, $excluded) || $snapshot === null || ! $snapshot->usable()) {
            return new LocalChallengeDecision;
        }
        $shape = HeaderShape::hash($headerNames);
        if (! $snapshot->hasShape($shape) || $snapshot->exempt($clientIp->ip)) {
            return new LocalChallengeDecision;
        }

        return new LocalChallengeDecision($this->configuration->localChallengeAction, 'header_shape', $shape);
    }
}
