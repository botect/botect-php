<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\ApiClient;
use Botect\Configuration;
use Botect\Contracts\VerdictCache;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\Operation;

final readonly class DeliverAction
{
    /** Older deliveries are dropped: Botect will not accept them and retrying cannot make them younger. */
    public const MAX_AGE_SECONDS = 3600;

    public function __construct(private ApiClient $client, private VerdictCache $cache, private Configuration $configuration) {}

    public function execute(Delivery $delivery): void
    {
        if ($delivery->createdAt < time() - self::MAX_AGE_SECONDS) {
            throw DeliveryException::expired(time() - $delivery->createdAt, self::MAX_AGE_SECONDS);
        }
        if ($delivery->operation === Operation::RefreshVerdict) {
            $verdict = $this->client->verdict($delivery->sessionToken, $delivery->payload['context']);
            if ($verdict->available()) {
                $this->cache->put($delivery->payload['cache_key'], $verdict, $this->configuration->verdictTtl);
            }
        } elseif ($delivery->operation === Operation::AssertLoggedIn) {
            $this->client->loggedIn($delivery->sessionToken);
            $this->cache->invalidate($delivery->sessionToken);
        } else {
            $this->client->serverIngest($delivery->operation, $delivery->payload, $delivery->id);
        }
    }
}
