<?php

declare(strict_types=1);

namespace Botect\Laravel\Middleware;

use Botect\Botect;
use Botect\Laravel\Contracts\VerdictHandler;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class EnforceVerdict
{
    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = null;
        if (config('botect.enforcement.enabled')) {
            try {
                $botect = $this->container->make(Botect::class);
                $session = $request->attributes->get('botect.session_token') ?? $botect->sessionToken((string) $request->cookie(config('botect.cookie_name'), ''));
                if (is_string($session)) {
                    $context = $request->route() === null ? [] : ['path' => '/'.ltrim($request->route()->uri(), '/')];
                    $verdict = $botect->verdict($session, $context);
                    $request->attributes->set('botect.verdict', $verdict);
                    $response = $this->container->make(VerdictHandler::class)->handle($request, $verdict);
                }
            } catch (Throwable) {
                $response = null;
            }
        }

        return $response ?? $next($request);
    }
}
