<?php

declare(strict_types=1);

namespace Botect;

use Botect\Actions\AssertLoggedInAction;
use Botect\Actions\DeliverAction;
use Botect\Actions\ForwardEventsAction;
use Botect\Actions\GetVerdictAction;
use Botect\Actions\MatchChallengeListAction;
use Botect\Actions\RecordPageAction;
use Botect\Actions\RefreshChallengeListAction;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\EnforcementStore;
use Botect\Contracts\HttpTransport;
use Botect\Contracts\TimeoutAwareTransport;
use Botect\Contracts\VerdictCache;
use Botect\Delivery\DeferredDispatcher;
use Botect\Http\CurlTransport;
use Botect\Storage\FileEnforcementStore;
use Botect\Storage\FileSpool;
use Botect\Storage\FileVerdictCache;
use Botect\Storage\MemoryEnforcementStore;
use Botect\Storage\MemoryVerdictCache;
use Botect\Support\IpRangeSet;
use Botect\Support\PageTokens;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class Botect
{
    private EnforcementStore $enforcementStore;

    private RefreshChallengeListAction $challengeRefresh;

    public function __construct(public Configuration $configuration, private Dispatcher $dispatcher, private VerdictCache $cache, private HttpTransport $transport,
        ?EnforcementStore $enforcementStore = null, private ?\Closure $challengeLogger = null)
    {
        $this->enforcementStore = $enforcementStore ?? new MemoryEnforcementStore;
        $this->challengeRefresh = new RefreshChallengeListAction($configuration, $this->enforcementStore);
    }

    public static function create(Configuration $configuration, ?string $storageDirectory = null, ?HttpTransport $transport = null, string $delivery = 'deferred', ?VerdictCache $cache = null, ?EnforcementStore $enforcementStore = null, ?\Closure $challengeLogger = null): self
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
        $enforcementStore ??= $storageDirectory === null ? new MemoryEnforcementStore
            : new FileEnforcementStore(rtrim($storageDirectory, '/').'/enforcement/'.$configuration->enforcementScope());
        $action = new DeliverAction(new ApiClient($configuration, $transport), $cache, $configuration, $enforcementStore);
        $dispatcher = $delivery === 'spool'
            ? new FileSpool($directory.'/spool')
            : new DeferredDispatcher($action->execute(...));
        if ($dispatcher instanceof DeferredDispatcher) {
            $dispatcher->registerShutdown();
        }

        return new self($configuration, $dispatcher, $cache, $transport, $enforcementStore, $challengeLogger);
    }

    public function localChallenge(string $method, array $headerNames, ?ClientIp $clientIp, ?bool $loggedIn, string $userAgent, bool $htmlNavigation, bool $excluded = false): LocalChallengeDecision
    {
        try {
            $matcher = new MatchChallengeListAction($this->configuration);
            if (! $matcher->eligible($method, $clientIp, $loggedIn, $htmlNavigation, $excluded)) {
                return new LocalChallengeDecision;
            }
            $snapshot = $this->challengeRefresh->snapshot();
            if ($this->challengeRefresh->refreshDue($snapshot)) {
                $this->challengeRefresh->dispatch($this->dispatcher, true);
            }

            return $matcher->execute($snapshot, $method, $headerNames, $clientIp, $loggedIn, $htmlNavigation, $excluded);
        } catch (Throwable) {
            return new LocalChallengeDecision;
        }
    }

    /** Explicit blocking maintenance refresh, using delivery HTTP budgets. */
    public function refreshChallengeList(): bool
    {
        try {
            $delivery = $this->challengeRefresh->delivery();
            if ($delivery === null) {
                return false;
            }
            $this->deliver($delivery);

            return $this->challengeRefresh->snapshot()?->usable() ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    public function dispatchChallengeListRefresh(): bool
    {
        return $this->challengeRefresh->dispatch($this->dispatcher);
    }

    /** Safe event fields only; call issuance events after local page-report acceptance. */
    public function logChallenge(string $event, string $source, ClientIp $clientIp, ?string $reason = null, ?string $shape = null): void
    {
        try {
            if ($this->challengeLogger === null || ! in_array($event, ['challenge_issued', 'block_issued', 'clearance_issued'], true) || ! in_array($source, ['crowd', 'verdict'], true)) {
                return;
            }
            $context = ['source' => $source, 'network_prefix' => IpRangeSet::networkPrefix($clientIp->ip)];
            if ($event !== 'clearance_issued') {
                $context += ['reason' => $source === 'crowd' ? 'header_shape' : 'session_verdict', 'shape' => is_string($shape) && preg_match('/^[a-f0-9]{32}$/D', $shape) ? $shape : null];
            }
            ($this->challengeLogger)($event, $context);
        } catch (Throwable) {
        }
    }

    /** Explicit immediate lookup; waits up to the lookup timeout, without retries.
     * @param  array<string, string>  $context
     */
    public function lookupVerdict(string $sessionToken, array $context = [], ?bool $loggedIn = null): Verdict
    {
        try {
            return (new ApiClient($this->configuration, $this->transport))->verdict($sessionToken, $context, $loggedIn);
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
    public function verdict(string $sessionToken, array $context = [], ?bool $loggedIn = null): Verdict
    {
        return (new GetVerdictAction($this->configuration, $this->dispatcher, $this->cache))->execute($sessionToken, $context, $loggedIn);
    }

    /** True means accepted by the configured dispatcher, not confirmed by Botect. */
    public function loggedIn(string $sessionToken): bool
    {
        return (new AssertLoggedInAction($this->configuration, $this->dispatcher, $this->cache))->execute($sessionToken);
    }

    public function page(?string $sessionCookie = null, ?bool $loggedIn = null): Page
    {
        return (new PageTokens($this->configuration))->mint($sessionCookie, null, $loggedIn);
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
    public function recordPage(Page $page, string $method, string $path, array $headerNames = [], string $acceptLanguage = '', int $durationMs = 0, ClientIp|string|null $observedIp = null): bool
    {
        return (new RecordPageAction($this->configuration, $this->dispatcher))->execute($page, $method, $path, $headerNames, $acceptLanguage, $durationMs, $observedIp);
    }

    /**
     * Forward a browser event batch posted to the local ingest endpoint.
     *
     * `$sessionCookie` is the raw signed session cookie from the visitor's own
     * request. A verified cookie naming a different session than the page
     * token throws SessionMismatchException; null or an unverifiable cookie
     * skips the comparison.
     *
     * `$relayedAt` is a server-captured receipt time; defaults to action entry.
     *
     * @param  array<string, mixed>  $body
     */
    public function forwardEvents(string $pageToken, array $body, ?string $idempotencyKey = null, ?string $sessionCookie = null, ClientIp|string|null $observedIp = null, ?DateTimeImmutable $relayedAt = null): bool
    {
        return (new ForwardEventsAction($this->configuration, $this->dispatcher, new PageTokens($this->configuration)))->execute($pageToken, $body, $idempotencyKey, $sessionCookie, $observedIp, $relayedAt);
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
        (new DeliverAction(new ApiClient($this->configuration, $transport), $this->cache, $this->configuration, $this->enforcementStore))->execute($delivery);
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
