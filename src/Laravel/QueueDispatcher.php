<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Contracts\Dispatcher;
use Botect\Delivery;
use Botect\Laravel\Jobs\DeliverJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class QueueDispatcher implements Dispatcher
{
    public function __construct(private Bus $bus, private Repository $config, private LoggerInterface $logger, private Cache $cache) {}

    public function dispatch(Delivery $delivery): bool
    {
        try {
            $connection = $this->config->get('botect.queue_connection') ?: $this->config->get('queue.default');
            $driver = $this->config->get('queue.connections.'.$connection.'.driver');
            if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                $this->logger->warning('Botect requires an asynchronous queue connection; delivery skipped.');

                return false;
            }
            $job = (new DeliverJob($delivery))->onConnection($connection)->onQueue($this->config->get('botect.queue', 'botect'));
            $lock = new UniqueLock($this->cache);
            if (! $lock->acquire($job)) {
                return true;
            }
            try {
                $this->bus->dispatch($job);
            } catch (Throwable $exception) {
                $lock->release($job);
                throw $exception;
            }

            return true;
        } catch (Throwable) {
            $this->logger->warning('Botect could not queue a delivery; request allowed.');

            return false;
        }
    }
}
