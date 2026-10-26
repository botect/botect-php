<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\ClientIp;
use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Exceptions\SessionMismatchException;
use Botect\Operation;
use Botect\Support\EventBatch;
use Botect\Support\PageTokens;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ForwardEventsAction
{
    public function __construct(private Configuration $configuration, private Dispatcher $dispatcher, private PageTokens $tokens) {}

    /** @param array<string, mixed> $body */
    public function execute(string $pageToken, array $body, ?string $idempotencyKey = null, ?string $sessionCookie = null, ClientIp|string|null $observedIp = null, ?DateTimeImmutable $relayedAt = null): bool
    {
        $relayedAt ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
        if ($relayedAt < new DateTimeImmutable('1970-01-02T00:00:00Z') || $relayedAt > new DateTimeImmutable('2038-01-18T00:00:00Z')) {
            throw new InvalidArgumentException('Invalid relay timestamp.');
        }
        // Retain the v0.1.7 hash and field order, independently of relay time.
        $legacyEvents = array_map(static fn (array $event): array => [
            'request_id' => $event['request_id'], 'type' => $event['type'],
            'received_at' => $event['sent_at'], 'payload' => $event['payload'],
        ], $events);
        $batchKey = $idempotencyKey ?? hash('sha256', json_encode($legacyEvents, JSON_THROW_ON_ERROR));
        $relayTimestamp = $relayedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        foreach ($events as &$event) {
            $event['relayed_at'] = $relayTimestamp;
        }
        unset($event);
        $payload = ['schema_version' => 1, 'page_id' => $page->id, 'session_token' => $page->sessionToken, 'events' => $events];
        if ($page->loggedIn !== null) {
            $payload['logged_in'] = $page->loggedIn;
        }

        // A plain string is the application's own answer: explicit, not inferred.
        $observed = $observedIp instanceof ClientIp ? $observedIp : ClientIp::fromString($observedIp, ClientIp::SOURCE_RESOLVER);
        if ($observed !== null) {
            $payload += $observed->toPayload();
        }

        return $this->dispatcher->dispatch(Delivery::make(Operation::ForwardEvents, $page->sessionToken, $payload, $page->id.'|'.$batchKey));
    }
}
