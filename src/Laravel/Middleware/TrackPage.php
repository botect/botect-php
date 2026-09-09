<?php

declare(strict_types=1);

namespace Botect\Laravel\Middleware;

use Botect\Botect;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class TrackPage
{
    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $page = $botect = null;
        $started = hrtime(true);
        try {
            $excluded = $request->is(ltrim((string) config('botect.ingest_path'), '/'));
            foreach (config('botect.tracking.except', []) as $pattern) {
                $excluded = $excluded || $request->is($pattern);
            }
            if (! $excluded && $request->isMethod('GET') && config('botect.tracking.enabled') && config('botect.server_ingest_enabled')) {
                $botect = $this->container->make(Botect::class);
                $page = $botect->page($request->cookie(config('botect.cookie_name')));
                $request->attributes->set('botect.page', $page);
                $request->attributes->set('botect.session_token', $page->sessionToken);
            }
        } catch (Throwable) {
            $page = null;
        }

        $response = $next($request);
        if ($page === null || $botect === null || ! $response->isSuccessful() || $response instanceof StreamedResponse
            || ! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')
            || $response->headers->has('Content-Encoding') || ! is_string($response->getContent())) {
            return $response;
        }
        try {
            $content = $response->getContent();
            if (config('botect.tracking.inject_collector') && ! str_contains($content, 'data-botect-sdk')) {
                $position = strripos($content, '</head>');
                if ($position === false) {
                    $position = strripos($content, '</body>');
                }
                if ($position !== false) {
                    $content = substr_replace($content, $botect->collector($page, $request->attributes->get('csp_nonce')), $position, 0);
                    $response->setContent($content);
                }
            }
            $response->headers->setCookie(cookie(config('botect.cookie_name'), $botect->sessionCookie($page), config('botect.cookie_minutes'), '/', null, $request->isSecure(), true, false, 'lax'));
            $response->headers->set('Cache-Control', 'private, no-store');
            foreach (['Content-Length', 'ETag', 'Last-Modified'] as $header) {
                $response->headers->remove($header);
            }
            $botect->recordPage($page, $request->method(), $request->getPathInfo(), array_keys($request->headers->all()), (string) $request->header('Accept-Language', ''), (int) ((hrtime(true) - $started) / 1000000));
        } catch (Throwable) {
            return $response;
        }

        return $response;
    }
}
