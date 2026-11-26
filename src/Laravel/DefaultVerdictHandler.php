<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Botect;
use Botect\ClientIp;
use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Laravel\Http\ChallengeController;
use Botect\Page;
use Botect\Support\IpRangeSet;
use Botect\Verdict;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DefaultVerdictHandler implements VerdictHandler
{
    private function challenge(Request $request): ?Response
    {
        $botect = app(Botect::class);
        $page = $request->attributes->get('botect.page');
        $ip = $request->attributes->get('botect.client_ip');
        if (! $botect->configuration->localChallengesEnabled || ! $page instanceof Page || ! $ip instanceof ClientIp
            || $ip->inferred || ! IpRangeSet::publicAddress($ip->ip)) {
            return null;
        }

        return ChallengeController::page($request, $botect, $page, $ip, $request->attributes->get('botect.challenge_source', 'verdict'));
    }

    public function handle(Request $request, Verdict $verdict): ?Response
    {
        return match ($verdict->action) {
            'challenge' => $this->challenge($request),
            'block' => response('Forbidden', 403, ['Cache-Control' => 'private, no-store']),
            'delay' => response('Please retry later.', 429, ['Retry-After' => '5', 'Cache-Control' => 'private, no-store']),
            default => null,
        };
    }
}
