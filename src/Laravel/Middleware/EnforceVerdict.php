<?php

declare(strict_types=1);

namespace Botect\Laravel\Middleware;

use Botect\Botect;
use Botect\ClientIp;
use Botect\Laravel\ClientIp\ClientIpResolverFactory;
use Botect\Laravel\Contracts\VerdictHandler;
use Botect\Laravel\Http\ChallengeController;
use Botect\Laravel\Http\VisitorSessionCookie;
use Botect\Laravel\LoggedInState;
use Botect\Page;
use Botect\Support\ChallengeTokens;
use Botect\Verdict;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class EnforceVerdict
{
    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = null;
        $started = hrtime(true);
        if (config('botect.enforcement.enabled')) {
            try {
                $botect = $this->container->make(Botect::class);
                $cookie = $this->container->make(VisitorSessionCookie::class)->read($request);
                $session = $request->attributes->get('botect.session_token') ?? $botect->sessionToken((string) $cookie);
                $page = $request->attributes->get('botect.page');
                $loggedIn = $page instanceof Page ? $page->loggedIn : LoggedInState::of($this->container, $request);
                $verdict = null;
                if (is_string($session)) {
                    $context = $request->route() === null ? [] : ['path' => '/'.ltrim($request->route()->uri(), '/')];
                    $verdict = $botect->verdict($session, $context, $loggedIn);
                    $request->attributes->set('botect.verdict', $verdict);
                    if ($verdict->action === 'challenge' && $botect->configuration->localChallengesEnabled) {
                        $response = $this->deny($request, $botect, $verdict, 'verdict', $loggedIn, $started);
                    } else {
                        $response = $this->container->make(VerdictHandler::class)->handle($request, $verdict);
                    }
                }
                if ($response === null && $botect->configuration->localChallengesEnabled && $botect->configuration->serverIngestEnabled) {
                    $excluded = $request->is(ltrim($botect->configuration->ingestPath, '/'), ltrim($botect->configuration->challengePath, '/'));
                    foreach (config('botect.enforcement.local_challenges.except', []) as $pattern) {
                        $excluded = $excluded || $request->is($pattern);
                    }
                    $excluded = $excluded || preg_match('/\b(?:prefetch|prerender)\b/i', $request->header('Sec-Purpose', '').' '.$request->header('Purpose', '')) === 1;
                    $html = $this->htmlNavigation($request);
                    $decision = $botect->localChallenge($request->method(), array_keys($request->headers->all()), $this->clientIp($request), $loggedIn, (string) $request->userAgent(), $html, $excluded);
                    if ($decision->action !== 'allow') {
                        $request->attributes->set('botect.challenge_shape', $decision->shape);
                        $response = $this->deny($request, $botect, new Verdict(action: $decision->action, reason: (string) $decision->reason), 'crowd', $loggedIn, $started);
                    }
                }
            } catch (Throwable) {
                $response = null;
            }
        }

        return $response ?? $next($request);
    }

    private function htmlNavigation(Request $request): bool
    {
        if ($request->isJson() || $request->expectsJson() || $request->ajax()) {
            return false;
        }
        $accept = AcceptHeader::fromString(strtolower((string) $request->header('Accept', '*/*')));
        $html = $accept->get('text/html') ?? $accept->get('text/*') ?? $accept->get('*/*');

        return $html !== null && $html->getQuality() > 0;
    }

    private function clientIp(Request $request): ?ClientIp
    {
        if (! $request->attributes->has('botect.client_ip')) {
            $request->attributes->set('botect.client_ip', ClientIpResolverFactory::resolve($this->container)->resolve($request));
        }

        return $request->attributes->get('botect.client_ip');
    }

    private function deny(Request $request, Botect $botect, Verdict $verdict, string $source, ?bool $loggedIn, int $started): ?Response
    {
        $ip = $this->clientIp($request);
        $cookieReader = $this->container->make(VisitorSessionCookie::class);
        $cookie = $cookieReader->read($request);
        $session = $request->attributes->get('botect.session_token') ?? $botect->sessionToken((string) $cookie);
        $tokens = new ChallengeTokens($botect->configuration);
        if ($verdict->action === 'challenge' && $ip !== null && is_string($session)) {
            $clearance = $cookieReader->read($request, 'botect_challenge_pass', fn (string $value): bool => $tokens->hasClearance($value, $session, $ip, (string) $request->userAgent(), $request->getSchemeAndHttpHost()));
            if ($clearance !== null) {
                return null;
            }
        }
        $page = $request->attributes->get('botect.page');
        if (! $page instanceof Page) {
            if ($cookie === null && is_string($session)) {
                $cookie = $botect->sessionCookie(new Page('', $session, '', time()));
            }
            $page = $botect->page($cookie, $loggedIn);
            $request->attributes->set('botect.page', $page);
            $request->attributes->set('botect.session_token', $page->sessionToken);
        }
        $request->attributes->set('botect.challenge_source', $source);
        if ($source === 'crowd' && ! $request->attributes->get('botect.page_reported')) {
            if (! $botect->recordPage($page, $request->method(), $request->getPathInfo(), array_keys($request->headers->all()), (string) $request->header('Accept-Language', ''), (int) ((hrtime(true) - $started) / 1000000), $ip)) {
                return null;
            }
            $request->attributes->set('botect.page_reported', true);
        }
        $response = $this->container->make(VerdictHandler::class)->handle($request, $verdict);
        if ($response === null) {
            return null;
        }
        $request->attributes->set('botect.denied', true);
        ChallengeController::privateResponse($response);
        if ($source === 'crowd' && $verdict->action === 'block') {
            $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        }
        $response->headers->setCookie(cookie(config('botect.cookie_name'), $botect->sessionCookie($page), config('botect.cookie_minutes'), '/', null, $request->isSecure(), true, false, 'lax'));
        if ($ip !== null) {
            $botect->logChallenge($verdict->action === 'block' ? 'block_issued' : 'challenge_issued', $source, $ip, $source === 'crowd' ? 'header_shape' : 'session_verdict', $request->attributes->get('botect.challenge_shape'));
        }

        return $response;
    }
}
