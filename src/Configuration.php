<?php

declare(strict_types=1);

namespace Botect;

use InvalidArgumentException;

final readonly class Configuration
{
    public function __construct(
        public string $siteKey,
        #[\SensitiveParameter] public ?string $privateKey = null,
        public string $apiUrl = 'https://api.botect.ai/v1',
        public string $collectorUrl = 'https://cdn.botect.ai/v1/sdk.js',
        public bool $serverIngestEnabled = false,
        public string $ingestPath = '/_botect/events',
        public int $connectTimeoutMs = 200,
        public int $timeoutMs = 1000,
        public int $verdictTtl = 10,
        public int $pageTokenTtl = 900,
        public int $maxBodyBytes = 262144,
    ) {
        if ($siteKey === '' || preg_match('/[\x00-\x20\x7f]/', $siteKey)) {
            throw new InvalidArgumentException('A non-empty site key without whitespace is required.');
        }
        if ($privateKey !== null && ($privateKey === '' || preg_match('/[\x00-\x20\x7f]/', $privateKey))) {
            throw new InvalidArgumentException('The private key must not be empty or contain whitespace.');
        }
        foreach ([$apiUrl, $collectorUrl] as $url) {
            $parts = preg_match('/[\x00-\x20\x7f]/', $url) ? false : parse_url($url);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('Botect URLs must be absolute HTTPS URLs without credentials, query strings, or fragments.');
            }
        }
        if (! preg_match('~^/(?!/)[a-zA-Z0-9/_-]+$~D', $ingestPath)) {
            throw new InvalidArgumentException('The ingest path must be a local absolute path.');
        }
        if ($connectTimeoutMs < 1 || $timeoutMs < $connectTimeoutMs || $timeoutMs > 10000 || $verdictTtl < 1 || $verdictTtl > 60 || $pageTokenTtl < 60 || $pageTokenTtl > 3600 || $maxBodyBytes < 1024 || $maxBodyBytes > 1048576) {
            throw new InvalidArgumentException('Invalid SDK timeout, lifetime, or payload limit.');
        }
        if ($serverIngestEnabled && $privateKey === null) {
            throw new InvalidArgumentException('Server ingest requires a private key.');
        }
    }

    public function namespace(): string
    {
        return hash('sha256', $this->apiUrl.'|'.$this->siteKey);
    }
}
