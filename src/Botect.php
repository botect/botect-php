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
use Botect\Contracts\VerdictCache;
use Botect\Http\CurlTransport;
use Botect\Storage\FileSpool;
use Botect\Storage\FileVerdictCache;
use Botect\Support\PageTokens;
use LogicException;

final readonly class Botect
{
    public function __construct(public Configuration $configuration, private Dispatcher $dispatcher, private VerdictCache $cache, private HttpTransport $transport) {}

    public static function create(Configuration $configuration, string $storageDirectory, ?HttpTransport $transport = null): self
    {
        $storageDirectory = rtrim($storageDirectory, '/').'/'.$configuration->namespace();

        return new self($configuration, new FileSpool($storageDirectory.'/spool'), new FileVerdictCache($storageDirectory.'/cache'), $transport ?? new CurlTransport($configuration));
    }

    /** Returns cached evidence or allow, and schedules refresh without a Botect round trip.
     * @param  array<string, string>  $context
     */
    public function verdict(string $sessionToken, array $context = []): Verdict
    {
        return (new GetVerdictAction($this->configuration, $this->dispatcher, $this->cache))->execute($sessionToken, $context);
    }

    /** True means queued locally, not that Botect has accepted the assertion. */
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

    /** @param array<string, mixed> $body */
    public function forwardEvents(string $pageToken, array $body, ?string $idempotencyKey = null): bool
    {
        return (new ForwardEventsAction($this->configuration, $this->dispatcher, new PageTokens($this->configuration)))->execute($pageToken, $body, $idempotencyKey);
    }

    public function collector(?Page $page = null, ?string $cspNonce = null): string
    {
        return (new Collector($this->configuration))->render($page, $cspNonce);
    }

    public function deliver(Delivery $delivery): void
    {
        (new DeliverAction(new ApiClient($this->configuration, $this->transport), $this->cache, $this->configuration))->execute($delivery);
    }

    /** Explicit blocking worker entry point, for cron / CLI only. */
    public function flush(int $limit = 100): FlushResult
    {
        if (! $this->dispatcher instanceof FileSpool) {
            throw new LogicException('Use the configured queue worker to process deliveries.');
        }
        $result = $this->dispatcher->flush($this->deliver(...), $limit);
        if ($this->cache instanceof FileVerdictCache) {
            $this->cache->prune();
        }

        return $result;
    }
}
