<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Exceptions\SessionMismatchException;
use Botect\Operation;
use Botect\Support\EventBatch;
use Botect\Support\PageTokens;
use InvalidArgumentException;

final readonly class ForwardEventsAction
{
    public function __construct(private Configuration $configuration, private Dispatcher $dispatcher, private PageTokens $tokens) {}

    /** @param array<string, mixed> $body */
    public function execute(string $pageToken, array $body, ?string $idempotencyKey = null, ?string $sessionCookie = null): bool
    {
        if (! $this->configuration->serverIngestEnabled) {
            return false;
        }
        $page = $this->tokens->verify($pageToken);
        if ($page === null || ($body['site_key'] ?? $this->configuration->siteKey) !== $this->configuration->siteKey) {
            throw new InvalidArgumentException('Invalid page token or site key.');
        }
        // The visitor's own cookie outranks the token in the page: a cached page
        // carries the first visitor's token to everyone. An absent or
        // unverifiable cookie proves nothing and must not block the batch.
        if ($sessionCookie !== null) {
            $visitorSession = $this->tokens->readCookie($sessionCookie);
            if ($visitorSession !== null && ! hash_equals($page->sessionToken, $visitorSession)) {
                throw new SessionMismatchException($page->id);
            }
        }
        if (strlen(json_encode($body, JSON_THROW_ON_ERROR)) > $this->configuration->maxBodyBytes) {
            throw new InvalidArgumentException('Event batch too large.');
        }
        $events = EventBatch::validate($body);
        if ($idempotencyKey !== null && ! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
        $batchKey = $idempotencyKey ?? hash('sha256', json_encode($events, JSON_THROW_ON_ERROR));
        $payload = ['schema_version' => 1, 'page_id' => $page->id, 'session_token' => $page->sessionToken, 'events' => $events];

        return $this->dispatcher->dispatch(Delivery::make(Operation::ForwardEvents, $page->sessionToken, $payload, $page->id.'|'.$batchKey));
    }
}
