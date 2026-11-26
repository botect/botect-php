<?php

declare(strict_types=1);

namespace Botect\Laravel\Contracts;

use Botect\Verdict;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface VerdictHandler
{
    /** Null continues the request. The default handler uses built-in challenges only with local opt-in; custom handlers retain control. */
    public function handle(Request $request, Verdict $verdict): ?Response;
}
