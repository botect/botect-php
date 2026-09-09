<?php

declare(strict_types=1);

namespace Botect\Laravel\Contracts;

use Botect\Verdict;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface VerdictHandler
{
    /** Null continues the request. Implement challenges through your application's challenge flow. */
    public function handle(Request $request, Verdict $verdict): ?Response;
}
