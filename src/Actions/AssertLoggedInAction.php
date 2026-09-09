<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\VerdictCache;
use Botect\Delivery;
use Botect\Operation;
use Botect\Support\SessionToken;
use Throwable;

final readonly class AssertLoggedInAction
{
    public function __construct(private Configuration $configuration, private Dispatcher $dispatcher, private VerdictCache $cache) {}

    public function execute(string $sessionToken): bool
    {
        try {
            SessionToken::validate($sessionToken);
            if ($this->configuration->privateKey === null) {
                return false;
            }
            $this->cache->invalidate($sessionToken);

            return $this->dispatcher->dispatch(Delivery::make(Operation::AssertLoggedIn, $sessionToken));
        } catch (Throwable) {
            return false;
        }
    }
}
