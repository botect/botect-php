<?php

declare(strict_types=1);

namespace Botect\Testing;

use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Operation;

final class FakeDispatcher implements Dispatcher
{
    /** @var list<Delivery> */
    public array $deliveries = [];

    public bool $accept = true;

    public function dispatch(Delivery $delivery): bool
    {
        if ($this->accept) {
            $this->deliveries[] = $delivery;
        }

        return $this->accept;
    }

    /** @return list<Delivery> */
    public function forOperation(Operation $operation): array
    {
        return array_values(array_filter($this->deliveries, static fn (Delivery $delivery): bool => $delivery->operation === $operation));
    }
}
