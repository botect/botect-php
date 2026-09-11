<?php

declare(strict_types=1);

namespace Botect\Contracts;

/**
 * A transport whose timeouts the SDK may adjust per call site.
 *
 * Optional. An immediate lookup blocks a visitor request and uses the short
 * lookup limits; a background delivery runs in a queue or spool worker where
 * nothing waits on it, and uses the longer delivery limits. A transport that
 * implements only HttpTransport keeps the limits it was built with for both.
 */
interface TimeoutAwareTransport extends HttpTransport
{
    /** A transport using these limits. May return the same instance or a copy. */
    public function withTimeouts(int $connectTimeoutMs, int $timeoutMs): static;
}
