<?php

declare(strict_types=1);

namespace Botect\Actions;

use Botect\Configuration;
use Botect\Contracts\Dispatcher;
use Botect\Contracts\VerdictCache;
use Botect\Delivery;
use Botect\Operation;
use Botect\Support\SessionToken;
use Botect\Verdict;
use InvalidArgumentException;
use Throwable;

final readonly class GetVerdictAction
{
    public function __construct(private Configuration $configuration, private Dispatcher $dispatcher, private VerdictCache $cache) {}

    /** @param array<string, string> $context */
    public function execute(string $sessionToken, array $context = []): Verdict
    {
        try {
            SessionToken::validate($sessionToken);
            if ($this->configuration->privateKey === null) {
                return new Verdict;
            }
            foreach ($context as $key => $value) {
                if (! in_array($key, ['path', 'ip', 'country', 'ua'], true) || ! is_string($value) || strlen($value) > 2048) {
                    throw new InvalidArgumentException('Invalid verdict context.');
                }
            }
            ksort($context);
            $key = hash('sha256', $this->configuration->namespace().'|'.$sessionToken.'|'.$this->cache->generation($sessionToken).'|'.json_encode($context, JSON_THROW_ON_ERROR));
            $cached = $this->cache->get($key);
            if ($cached !== null && $cached->available()) {
                return $cached;
            }
            $this->dispatcher->dispatch(Delivery::make(Operation::RefreshVerdict, $sessionToken, ['context' => $context, 'cache_key' => $key], $key));
        } catch (Throwable) {
            return new Verdict;
        }

        return new Verdict;
    }
}
