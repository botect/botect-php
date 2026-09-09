<?php

declare(strict_types=1);

namespace Botect\Laravel\Http;

use Botect\Botect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
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
            $queued = app(Botect::class)->forwardEvents($token, $body, $request->header('Idempotency-Key'));

            return response()->json(['queued' => $queued], $queued ? 202 : 503, ['Cache-Control' => 'no-store']);
        } catch (InvalidArgumentException|JsonException) {
            return response()->json(['error' => 'Invalid payload or page token'], 422);
        } catch (Throwable) {
            return response()->json(['queued' => false], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
