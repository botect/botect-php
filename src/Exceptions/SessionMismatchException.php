<?php

declare(strict_types=1);

namespace Botect\Exceptions;

use InvalidArgumentException;

/**
 * The page token was minted for one visitor session and presented by another.
 *
 * The usual cause is a tracked page served from a CDN or full-page cache:
 * everyone who receives the cached copy carries the first visitor's token.
 * An InvalidArgumentException so handlers that already map that to a 4xx keep
 * rejecting the batch instead of attributing it. The message names no tokens.
 */
final class SessionMismatchException extends InvalidArgumentException
{
    public function __construct(public readonly string $pageId)
    {
        parent::__construct('The page token was minted for a different visitor session than the one sending it.');
    }
}
