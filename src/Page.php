<?php

declare(strict_types=1);

namespace Botect;

final readonly class Page
{
    public function __construct(public string $id, public string $sessionToken, public string $token, public int $issuedAt, public bool $hadSessionCookie = false) {}
}
