<?php

declare(strict_types=1);

namespace Botect\Laravel\Contracts;

use Illuminate\Http\Request;

/**
 * Decides whether the visitor is signed in for a request.
 *
 * Name your implementation in `botect.logged_in.resolver` when your
 * application needs a guard or tenant-specific answer. Return null when the
 * application should not report a login state for the request.
 */
interface LoggedInResolver
{
    public function loggedIn(Request $request): ?bool;
}
