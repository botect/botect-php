<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Laravel\Contracts\LoggedInResolver;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\Request;

final readonly class DefaultLoggedInResolver implements LoggedInResolver
{
    public function __construct(private Factory $auth) {}

    public function loggedIn(Request $request): ?bool
    {
        return $this->auth->guard()->check();
    }
}
