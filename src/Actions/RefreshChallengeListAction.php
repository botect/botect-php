<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\ApiClient;
use Botect\ChallengeList;
use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\EnforcementStore;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Operation;
use Throwable;

final class RefreshChallengeListAction
{
    private ?ChallengeList $loaded = null;

    private ?array $loadedData = null;

    private int $nextAttemptAt = 0;

    public function __construct(private Configuration $configuration, private EnforcementStore $store) {}

    public function snapshot(): ?ChallengeList
    {
        $state = $this->store->read()['scopes'][$this->configuration->enforcementScope()] ?? [];
        $this->nextAttemptAt = $state['next_attempt_at'] ?? 0;
        $data = $state['snapshot'] ?? null;
        if ($data === null) {
            return null;
        }
        if ($this->loadedData !== $data) {
            try {
                $this->loaded = ChallengeList::fromStored($data, fn (): ?array => $this->store->readCatalogue($data['catalogue_id']));
            } catch (Throwable) {
                return null;
            }
            $this->loadedData = $data;
        }

        return $this->loaded;
    }

    public function refreshDue(?ChallengeList $snapshot): bool
    {
        return $this->nextAttemptAt <= time() && ($snapshot === null || $snapshot->refreshDue());
    }

    private function update(\Closure $change): mixed
    {
        return $this->store->update(function (array &$state) use ($change): mixed {
            $scope = $this->configuration->enforcementScope();
            $state['scopes'][$scope] ??= [];

            return $change($state['scopes'][$scope]);
        });
    }

    public function delivery(): ?Delivery
    {
        if (! $this->configuration->localChallengesEnabled || $this->configuration->privateKey === null) {
            return null;
        }
        $owner = bin2hex(random_bytes(16));
        $claimed = $this->update(static function (array &$state) use ($owner): bool {
            $now = time();
            if (($state['lease_expires_at'] ?? 0) > $now || ($state['next_attempt_at'] ?? 0) > $now) {
                return false;
            }
            $state['lease_owner'] = $owner;
            $state['lease_expires_at'] = $now + 30;
            $state['lease_execution'] = null;

            return true;
        });
        $scope = $this->configuration->enforcementScope();

        return $claimed ? Delivery::make(Operation::RefreshChallengeList, 'sdk_list_v1', ['store_scope' => $scope, 'lease_owner' => $owner], $scope.'|'.$owner) : null;
    }

    public function dispatch(Dispatcher $dispatcher, bool $lazy = false): bool
    {
        $delivery = null;
        try {
            if ($lazy && ! $this->store->persistent()) {
                return false;
            }
            $delivery = $this->delivery();
            if ($delivery === null) {
                return false;
            }
            if ($dispatcher->dispatch($delivery)) {
                return true;
            }
        } catch (Throwable) {
            // Refused dispatch and storage failures are fail-open.
        }
        if ($delivery !== null) {
            try {
                $this->update(static function (array &$state) use ($delivery): void {
                    if (($state['lease_owner'] ?? null) === $delivery->payload['lease_owner']) {
                        $state['lease_expires_at'] = 0;
                        $state['lease_owner'] = null;
                        $state['next_attempt_at'] = time() + 5;
                    }
                });
            } catch (Throwable) {
            }
        }

        return false;
    }

    public function execute(Delivery $delivery, ApiClient $client): void
    {
        if (($delivery->payload['store_scope'] ?? null) !== $this->configuration->enforcementScope()
            || ! is_string($delivery->payload['lease_owner'] ?? null) || ! $this->configuration->localChallengesEnabled) {
            return;
        }
        $owner = $delivery->payload['lease_owner'];
        $execution = bin2hex(random_bytes(16));
        $claimed = $this->update(static function (array &$state) use ($owner, $execution): bool {
            if (($state['lease_owner'] ?? null) !== $owner) {
                return false;
            }
            $wait = ($state['next_attempt_at'] ?? 0) - time();
            if ($wait > 0) {
                throw new DeliveryException(true, retryAfterSeconds: $wait);
            }
            if (($state['lease_expires_at'] ?? 0) > time() && ($state['lease_execution'] ?? null) !== null) {
                return false;
            }
            $state['lease_expires_at'] = time() + 30;
            $state['lease_execution'] = $execution;

            return true;
        });
        if (! $claimed) {
            return;
        }
        $failure = null;
        $snapshot = null;
        $metadata = null;
        $refreshAfter = null;
        try {
            $previous = $this->snapshot();
            $data = $client->challengeList($previous?->version());
            $snapshot = ChallengeList::fromArray($data, $previous);
            $refreshAfter = $data['refresh_after'];
            if ($data['enabled']) {
                $id = hash('sha256', $this->configuration->enforcementScope().'|'.json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));
                $this->store->writeCatalogue($id, $snapshot->catalogue());
                $metadata = $snapshot->metadata($id);
            }
        } catch (Throwable $exception) {
            $failure = $exception instanceof DeliveryException ? $exception : new DeliveryException(false, previous: $exception);
        }
        $published = $this->update(static function (array &$state) use ($owner, $execution, $metadata, $refreshAfter, $failure): bool {
            if (($state['lease_owner'] ?? null) !== $owner || ($state['lease_execution'] ?? null) !== $execution || ($state['lease_expires_at'] ?? 0) <= time()) {
                return false;
            }
            if ($failure === null || ! $failure->retryable) {
                $state['snapshot'] = $metadata;
            }
            $state['next_attempt_at'] = $failure === null ? ($metadata === null ? $refreshAfter : 0) : time() + 30;
            $state['lease_execution'] = null;
            $state['lease_expires_at'] = $failure?->retryable ? time() + 30 : 0;
            $state['lease_owner'] = $failure?->retryable ? $owner : null;

            return true;
        });
        if ($published && $failure !== null) {
            throw new DeliveryException($failure->retryable, $failure->status, $failure, retryAfterSeconds: $failure->retryable ? 30 : 0);
        }
    }
}
