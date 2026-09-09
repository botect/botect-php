<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Verdict;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DefaultVerdictHandler implements VerdictHandler
{
    public function handle(Request $request, Verdict $verdict): ?Response
    {
        return match ($verdict->action) {
            'block' => response('Forbidden', 403, ['Cache-Control' => 'private, no-store']),
            'delay' => response('Please retry later.', 429, ['Retry-After' => '5', 'Cache-Control' => 'private, no-store']),
            default => null,
        };
    }
}
