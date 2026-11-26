<?php

declare(strict_types=1);

namespace Botect\Contracts;

interface ResponseLimitAwareTransport extends HttpTransport
{
    public function withResponseLimit(int $bytes): static;
}
