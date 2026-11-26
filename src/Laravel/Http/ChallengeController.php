<?php

declare(strict_types=1);

namespace Botect\Laravel\Http;

use Botect\Actions\VerifyChallengeAction;
use Botect\Botect;
use Botect\ClientIp;
use Botect\Contracts\ChallengeRenderer;
use Botect\Laravel\ClientIp\ClientIpResolverFactory;
use Botect\Page;
use Botect\Support\ChallengeTokens;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ChallengeController
{
    public function __invoke(Request $request): Response
    {
        try {
            if (! config('botect.enforcement.local_challenges.enabled') || ! config('botect.server_ingest_enabled')) {
                return self::privateResponse(response('', 404));
            }
            if (! $request->isJson() || strlen($request->getContent()) > 4096
                || $request->header('Origin') !== $request->getSchemeAndHttpHost() || $request->header('Sec-Fetch-Site') === 'cross-site') {
                return self::privateResponse(response('Forbidden', 403));
            }
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
            $botect = app(Botect::class);
            $cookie = app(VisitorSessionCookie::class)->read($request);
            $session = $cookie === null ? null : $botect->sessionToken($cookie);
            $ip = ClientIpResolverFactory::resolve(app())->resolve($request);
            if (! is_array($body) || $session === null || $ip === null || $ip->inferred) {
                return self::privateResponse(response('Forbidden', 403));
            }
            $result = (new VerifyChallengeAction(new ChallengeTokens($botect->configuration)))->execute($body, $session, $ip, (string) $request->userAgent(), $request->getSchemeAndHttpHost());
            if ($result === null) {
                return self::privateResponse(response('Forbidden', 403));
            }
            $response = self::privateResponse(response('', 204));
            $response->headers->setCookie(cookie('botect_challenge_pass', $result['clearance'], $botect->configuration->clearanceSeconds / 60, '/', null, $request->isSecure(), true, false, 'lax'));
            $botect->logChallenge('clearance_issued', $result['source'], $ip);

            return $response;
        } catch (Throwable) {
            return self::privateResponse(response('Forbidden', 403));
        }
    }

    public static function page(Request $request, Botect $botect, Page $page, ClientIp $ip, string $source): Response
    {
        $context = (new ChallengeTokens($botect->configuration))->issue($page, $ip, (string) $request->userAgent(), $request->getSchemeAndHttpHost(), $request->getRequestUri(), $source);
        $nonce = $request->attributes->get('csp_nonce') ?? rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        if (! is_string($nonce) || ! preg_match('/^[A-Za-z0-9+\/_=-]{1,256}$/D', $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce.');
        }
        $context += ['verification_path' => $botect->configuration->challengePath, 'csp_nonce' => $nonce];
        $response = self::privateResponse(response(app(ChallengeRenderer::class)->render($context), 403, ['Content-Type' => 'text/html; charset=UTF-8']));
        $response->headers->set('Content-Security-Policy', "default-src 'none'; script-src 'nonce-".$nonce."'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");

        return $response;
    }

    public static function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        foreach (['ETag', 'Last-Modified', 'Content-Length'] as $header) {
            $response->headers->remove($header);
        }

        return $response;
    }
}
