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
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(LoggerInterface::class)->warning('Botect background delivery failed.', ['operation' => $this->delivery->operation->value]);
    }
}
