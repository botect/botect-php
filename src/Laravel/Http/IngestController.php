<?php

declare(strict_types=1);

namespace Botect\Laravel\Http;

use Botect\Botect;
use Botect\Exceptions\SessionMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

final class IngestController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            if (! config('botect.server_ingest_enabled')) {
                return response()->json([], 404);
            }
            $origin = $request->header('Origin');
            if (($origin !== null && $origin !== $request->getSchemeAndHttpHost()) || $request->header('Sec-Fetch-Site') === 'cross-site') {
                return response()->json(['error' => 'Invalid origin'], 403);
            }
            if (strlen($request->getContent()) > (int) config('botect.max_body_bytes')) {
                return response()->json(['error' => 'Payload too large'], 413);
            }
            if (! $request->isJson()) {
                return response()->json(['error' => 'JSON required'], 415);
            }
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $token = $request->query('page_token');
            if (! is_array($body) || ! is_string($token)) {
                throw new InvalidArgumentException('Invalid payload.');
            }
            $queued = app(Botect::class)->forwardEvents($token, $body, $request->header('Idempotency-Key'), app(VisitorSessionCookie::class)->read($request));

            return response()->json(['queued' => $queued], $queued ? 202 : 503, ['Cache-Control' => 'no-store']);
        } catch (SessionMismatchException $exception) {
            // Caught before InvalidArgumentException, its parent: this must
            // never degrade into the silent 422 it exists to replace.
            $this->warnOnce($exception->pageId, $request);

            return response()->json(['error' => 'Page token belongs to another session'], 409, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException|JsonException) {
            return response()->json(['error' => 'Invalid payload or page token'], 422, ['Cache-Control' => 'no-store']);
        } catch (Throwable) {
            return response()->json(['queued' => false], 503, ['Cache-Control' => 'no-store']);
        }
    }

    /**
     * One warning per page token, not per visitor: a cached page is minted
     * once and then hit by everyone. Neither the throttle nor the logger may
     * change the response.
     */
    private function warnOnce(string $pageId, Request $request): void
    {
        try {
            $first = app('cache')->store(config('botect.cache_store'))->add('botect:ingest-mismatch:'.$pageId, true, (int) config('botect.page_token_ttl'));
        } catch (Throwable) {
            $first = true;
        }
        if (! $first) {
            return;
        }
        try {
            app(LoggerInterface::class)->warning(
                'Botect rejected browser events whose page token was minted for another visitor; the tracked page was probably served from a CDN or full-page cache. Exclude it with tracking.except or use tracking.scope=manual.',
                ['page_id' => $pageId, 'path' => $this->refererPath($request)],
            );
        } catch (Throwable) {
            // Logging is best-effort.
        }
    }

    /**
     * The referring page's path, without its query string. The token carries
     * no path, and "which page is cached?" is the operator's one question. The
     * header is attacker-controlled, so it is capped and restricted to
     * printable ASCII, and it goes to the application's own log only.
     */
    private function refererPath(Request $request): ?string
    {
        $path = parse_url((string) $request->headers->get('Referer', ''), PHP_URL_PATH);

        return is_string($path) && preg_match('/^[\x21-\x7e]{1,255}$/D', $path) === 1 ? $path : null;
    }
}
