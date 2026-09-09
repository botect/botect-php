<?php

declare(strict_types=1);

namespace Botect\Exceptions;

use RuntimeException;

final class DeliveryException extends RuntimeException
{
    public function __construct(public readonly bool $retryable, public readonly int $status = 0)
    {
        parent::__construct($status === 0 ? 'Botect transport or response failure.' : 'Botect delivery returned HTTP '.$status.'.');
    }
}
