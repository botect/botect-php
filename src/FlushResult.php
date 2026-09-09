<?php

declare(strict_types=1);

namespace Botect;

final readonly class FlushResult
{
    public function __construct(public int $sent = 0, public int $retried = 0, public int $failed = 0) {}
}
