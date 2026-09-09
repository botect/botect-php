<?php

declare(strict_types=1);

namespace Botect\Storage;

use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Exceptions\DeliveryException;
use Botect\FlushResult;
use Throwable;

final readonly class FileSpool implements Dispatcher
{
    public function __construct(private string $directory, private int $capacity = 1000, private int $maxAttempts = 5) {}

    public function dispatch(Delivery $delivery): bool
    {
        $lock = false;
        try {
            Files::directory($this->directory);
            $lock = @fopen($this->directory.'/.enqueue.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $path = $this->directory.'/'.$delivery->id.'.json';
            if (is_file($path)) {
                return true;
            }
            if (count(glob($this->directory.'/*.json') ?: []) >= $this->capacity) {
                return false;
            }
            $data = ['delivery' => $delivery->toArray(), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()];
            if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 1048576) {
                return false;
            }
            Files::write($path, $data);

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Run from a worker or cron, never during an HTTP response.
     * @param  callable(Delivery): void  $deliver
     */
    public function flush(callable $deliver, int $limit = 100): FlushResult
    {
        Files::directory($this->directory);
        $lock = @fopen($this->directory.'/.worker.lock', 'c');
        if ($lock === false) {
            return new FlushResult;
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return new FlushResult;
            }
            $sent = $retried = $failed = $processed = 0;
            foreach (glob($this->directory.'/*.json') ?: [] as $path) {
                if ($processed >= max(0, $limit)) {
                    break;
                }
                $data = [];
                try {
                    $data = Files::read($path);
                    if ($data['available_at'] > time()) {
                        continue;
                    }
                    $processed++;
                    $delivery = Delivery::fromArray($data['delivery']);
                    if ($data['created_at'] < time() - 3600) {
                        throw new DeliveryException(false);
                    }
                    $deliver($delivery);
                    @unlink($path);
                    $sent++;
                } catch (Throwable $exception) {
                    $attempts = ($data['attempts'] ?? 0) + 1;
                    if ($exception instanceof DeliveryException && $exception->retryable && $attempts < $this->maxAttempts) {
                        $data['attempts'] = $attempts;
                        $data['available_at'] = time() + min(300, 2 ** $attempts);
                        Files::write($path, $data);
                        $retried++;
                    } else {
                        @unlink($path);
                        $failed++;
                    }
                }
            }

            return new FlushResult($sent, $retried, $failed);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
