<?php

declare(strict_types=1);

namespace Botect\Delivery;

use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\FlushResult;
use Closure;
use InvalidArgumentException;
use Throwable;

final class DeferredDispatcher implements Dispatcher
{
    /** @var array<string, Delivery> */
    private array $pending = [];

    private int $bytes = 0;

    private bool $draining = false;

    private bool $shutdownRegistered = false;

    /** @param Closure(Delivery): void $send */
    public function __construct(private readonly Closure $send, private readonly int $capacity = 10, private readonly int $budgetMs = 1000, private readonly int $maxBytes = 1048576)
    {
        if ($capacity < 1 || $budgetMs < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Deferred delivery limits must be positive.');
        }
    }

    public function dispatch(Delivery $delivery): bool
    {
        if ($this->draining) {
            return false;
        }
        if (isset($this->pending[$delivery->id])) {
            return true;
        }
        try {
            $size = strlen(json_encode($delivery->toArray(), JSON_THROW_ON_ERROR));
            if (count($this->pending) >= $this->capacity || $this->bytes + $size > $this->maxBytes) {
                return false;
            }
            $this->pending[$delivery->id] = $delivery;
            $this->bytes += $size;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Attempts each buffered delivery once; the budget is checked between requests. */
    public function drain(): FlushResult
    {
        if ($this->draining) {
            return new FlushResult;
        }
        $pending = $this->pending;
        $this->pending = [];
        $this->bytes = 0;
        $this->draining = true;
        $started = hrtime(true);
        $sent = $failed = 0;
        try {
            foreach ($pending as $delivery) {
                if ((hrtime(true) - $started) / 1000000 >= $this->budgetMs) {
                    $failed++;

                    continue;
                }
                try {
                    ($this->send)($delivery);
                    $sent++;
                } catch (Throwable) {
                    $failed++;
                }
            }
        } finally {
            $this->draining = false;
        }

        return new FlushResult(sent: $sent, failed: $failed);
    }

    /** Plain PHP only. Framework integrations drain through their own termination lifecycle. */
    public function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            // Run after the application's already-registered shutdown callbacks.
            register_shutdown_function(function (): void {
                if ($this->pending === []) {
                    return;
                }
                $error = error_get_last();
                if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    return;
                }
                try {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    ignore_user_abort(true);
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    $this->drain();
                } catch (Throwable) {
                    // Best effort: never alter the application's response on delivery failure.
                }
            });
        });
    }
}
