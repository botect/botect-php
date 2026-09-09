<?php

declare(strict_types=1);

namespace Botect\Contracts;

use Botect\Delivery;

interface Dispatcher
{
    /** Accept locally; implementations must never perform a Botect HTTP request here. */
    public function dispatch(Delivery $delivery): bool;
}
