<?php

declare(strict_types=1);

namespace Botect;

use Botect\Actions\AssertLoggedInAction;
use Botect\Actions\DeliverAction;
use Botect\Actions\ForwardEventsAction;
use Botect\Actions\GetVerdictAction;
use Botect\Actions\RecordPageAction;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\HttpTransport;
use Botect\Contracts\TimeoutAwareTransport;
use Botect\Contracts\VerdictCache;
use Botect\Delivery\DeferredDispatcher;
use Botect\Http\CurlTransport;
use Botect\Storage\FileSpool;
use Botect\Storage\FileVerdictCache;
use Botect\Storage\MemoryVerdictCache;
use Botect\Support\PageTokens;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class Botect
{
    public function __construct(public Configuration $configuration, private Dispatcher $dispatcher, private VerdictCache $cache, private HttpTransport $transport) {}

    public static function create(Configuration $configuration, ?string $storageDirectory = null, ?HttpTransport $transport = null, string $delivery = 'deferred', ?VerdictCache $cache = null): self
    {
        if (! in_array($delivery, ['deferred', 'spool'], true)) {
            throw new InvalidArgumentException('Use deferred or spool; Laravel queues are configured through the service provider.');
        }
        if ($delivery === 'spool' && ($storageDirectory === null || trim($storageDirectory) === '')) {
            throw new InvalidArgumentException('File-spool delivery requires a private storage directory.');
        }
        if ($storageDirectory !== null && trim($storageDirectory) === '') {
            throw new InvalidArgumentException('The storage directory must not be empty.');
        }
        $directory = $storageDirectory === null ? null : rtrim($storageDirectory, '/').'/'.$configuration->namespace();
        $cache ??= $directory === null ? new MemoryVerdictCache : new FileVerdictCache($directory.'/cache');
        $transport ??= new CurlTransport($configuration);
        $action = new DeliverAction(new ApiClient($configuration, $transport), $cache, $configuration);
        $dispatcher = $delivery === 'spool'
            ? new FileSpool($directory.'/spool')
            : new DeferredDispatcher($action->execute(...));
        if ($dispatcher instanceof DeferredDispatcher) {
            $dispatcher->registerShutdown();
        }

        return new self($configuration, $dispatcher, $cache, $transport);
    }

    /** Explicit immediate lookup; waits up to the lookup timeout, without retries.
     * @param  array<string, string>  $context
     */
    public function lookupVerdict(string $sessionToken, array $context = []): Verdict
    {
        try {
            return (new ApiClient($this->configuration, $this->transport))->verdict($sessionToken, $context);
        } catch (Throwable) {
            return new Verdict;
        }
    }

    /** Explicit lifecycle hook for long-running plain PHP hosts; call after sending the response. */
    public function sendPending(): FlushResult
    {
        if (! $this->dispatcher instanceof DeferredDispatcher) {
            throw new LogicException('sendPending() requires deferred delivery.');
        }

        return $this->dispatcher->drain();
    }

    /** Optional file-cache maintenance; run outside the visitor request path. */
    public function pruneVerdictCache(): int
    {
        return $this->cache instanceof FileVerdictCache ? $this->cache->prune() : 0;
    }

    /** Returns cached evidence or allow, and schedules refresh without a Botect round trip.
     * @param  array<string, string>  $context
     */
    public function verdict(string $sessionToken, array $context = []): Verdict
    {
        return (new GetVerdictAction($this->configuration, $this->dispatcher, $this->cache))->execute($sessionToken, $context);
    }

    /** True means accepted by the configured dispatcher, not confirmed by Botect. */
    public function loggedIn(string $sessionToken): bool
    {
        return (new AssertLoggedInAction($this->configuration, $this->dispatcher, $this->cache))->execute($sessionToken);
    }

    public function page(?string $sessionCookie = null): Page
    {
        return (new PageTokens($this->configuration))->mint($sessionCookie);
    }

    public function sessionCookie(Page $page): string
    {
        return (new PageTokens($this->configuration))->cookie($page);
    }

    public function sessionToken(string $cookie): ?string
    {
        return (new PageTokens($this->configuration))->readCookie($cookie);
    }

    /** @param list<string> $headerNames */
    public function recordPage(Page $page, string $method, string $path, array $headerNames = [], string $acceptLanguage = '', int $durationMs = 0): bool
    {
        return (new RecordPageAction($this->configuration, $this->dispatcher))->execute($page, $method, $path, $headerNames, $acceptLanguage, $durationMs);
    }

    /**
     * Forward a browser event batch posted to the local ingest endpoint.
     *
     * `$sessionCookie` is the raw signed session cookie from the visitor's own
     * request. A verified cookie naming a different session than the page
     * token throws SessionMismatchException; null or an unverifiable cookie
     * skips the comparison.
     *
     * @param  array<string, mixed>  $body
     */
    public function forwardEvents(string $pageToken, array $body, ?string $idempotencyKey = null, ?string $sessionCookie = null): bool
    {
        return (new ForwardEventsAction($this->configuration, $this->dispatcher, new PageTokens($this->configuration)))->execute($pageToken, $body, $idempotencyKey, $sessionCookie);
    }

    public function collector(?Page $page = null, ?string $cspNonce = null): string
    {
        return (new Collector($this->configuration))->render($page, $cspNonce);
    }

    /**
     * Send one queued or spooled delivery. Runs in a worker where nothing waits
     * on it, so it uses the delivery timeouts rather than the lookup timeouts;
     * deferred delivery keeps the lookup limits because it holds a PHP-FPM
     * worker after the response. A transport that cannot be re-timed keeps the
     * limits it was built with.
     */
    public function deliver(Delivery $delivery): void
    {
        $transport = $this->transport instanceof TimeoutAwareTransport
            ? $this->transport->withTimeouts($this->configuration->deliveryConnectTimeoutMs, $this->configuration->deliveryTimeoutMs)
            : $this->transport;
        (new DeliverAction(new ApiClient($this->configuration, $transport), $this->cache, $this->configuration))->execute($delivery);
    }

    /** Explicit blocking worker entry point, for cron / CLI only. */
    public function flush(int $limit = 100): FlushResult
    {
        if (! $this->dispatcher instanceof FileSpool) {
            throw new LogicException('flush() requires spool delivery; deferred sends automatically, and queues use their worker.');
        }
        $result = $this->dispatcher->flush($this->deliver(...), $limit);
        $this->pruneVerdictCache();

        return $result;
    }
}
