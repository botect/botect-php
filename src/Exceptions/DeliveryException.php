<?php

declare(strict_types=1);

namespace Botect\Exceptions;

use RuntimeException;
use Throwable;

final class DeliveryException extends RuntimeException
{
    /**
     * @param  Throwable|null  $previous  the transport or decoding error behind a failure,
     *                                    kept as the cause and summarised in the message so an error tracker shows
     *                                    a connect timeout, a read timeout and a DNS failure as different things
     */
    public function __construct(public readonly bool $retryable, public readonly int $status = 0, ?Throwable $previous = null)
    {
        $message = $status === 0 ? 'Botect transport or response failure.' : 'Botect delivery returned HTTP '.$status.'.';
        if ($previous !== null && $previous->getMessage() !== '') {
            $message .= ' Cause: '.self::redact($previous->getMessage());
        }
        parent::__construct($message, 0, $previous);
    }

    /** Session tokens appear in request URLs; they must not travel into logs or trackers. */
    private static function redact(string $message): string
    {
        $message = preg_replace('/sess_[A-Za-z0-9_-]+/', 'sess_[redacted]', $message) ?? $message;

        return strlen($message) > 200 ? substr($message, 0, 199).'…' : $message;
    }
}
