<?php

declare(strict_types=1);

namespace Botect;

final readonly class Collector
{
    public function __construct(private Configuration $configuration) {}

    public function render(?Page $page = null, ?string $cspNonce = null): string
    {
        $endpoint = rtrim($this->configuration->apiUrl, '/').'/events';
        if ($this->configuration->serverIngestEnabled && $page !== null) {
            $endpoint = $this->configuration->ingestPath.'?page_token='.rawurlencode($page->token);
        }
        $attributes = ['src' => $this->configuration->collectorUrl, 'data-site-key' => $this->configuration->siteKey, 'data-endpoint' => $endpoint];
        if ($cspNonce !== null) {
            $attributes['nonce'] = $cspNonce;
        }
        $html = '<script async data-botect-sdk';
        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
        }

        return $html.'></script>';
    }
}
