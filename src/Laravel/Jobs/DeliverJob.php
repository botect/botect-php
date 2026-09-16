<?php

declare(strict_types=1);

namespace Botect\Laravel\Jobs;

use Botect\Botect;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Psr\Log\LoggerInterface;
use Throwable;

final class DeliverJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return $this->delivery->id;
    }

    public int $tries = 5;

    public int $timeout = 15;

    public function __construct(public readonly Delivery $delivery) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [2, 10, 30, 120];
    }

    public function handle(Botect $botect): void
    {
        try {
            $botect->deliver($this->delivery);
        } catch (DeliveryException $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }
            // Called directly rather than by a queue worker: nothing to release
            // into, so surface the failure to the caller as before.
            if ($this->job === null) {
                throw $exception;
            }
            if ($this->attempts() >= $this->tries) {
                $this->fail($exception);

                return;
            }
            // A retryable failure (a Botect deploy, a slow response, a 5xx)
            // usually succeeds on a later attempt. Throwing would make the queue
            // worker report every attempt to the error tracker, so a routine
            // restart looked like data loss. Release instead: only a delivery
            // that finally fails is reported, once, by failed() with its reason.
            $backoff = $this->backoff();
            $this->release($backoff[min($this->attempts() - 1, count($backoff) - 1)]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LoggerInterface::class)->warning('Botect background delivery failed.', [
            'operation' => $this->delivery->operation->value,
            'age_seconds' => max(0, time() - $this->delivery->createdAt),
            'attempts' => $this->attempts(),
            'exception' => $exception === null ? null : $exception::class,
            'reason' => $exception?->getMessage(),
            'status' => $exception instanceof DeliveryException ? $exception->status : null,
            'retryable' => $exception instanceof DeliveryException ? $exception->retryable : null,
        ]);
    }
}
