<?php

declare(strict_types=1);

namespace Botect\Http;

use Botect\Configuration;
use Botect\Contracts\HttpTransport;
use Botect\Exceptions\DeliveryException;

final readonly class CurlTransport implements HttpTransport
{
    public function __construct(private Configuration $configuration) {}

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        if (! extension_loaded('curl')) {
            throw new DeliveryException(false);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new DeliveryException(true);
        }
        $responseBody = '';
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS => $this->configuration->connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $this->configuration->timeoutMs,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody): int {
                    if (strlen($responseBody) + strlen($chunk) > 262144) {
                        return 0;
                    }
                    $responseBody .= $chunk;

                    return strlen($chunk);
                },
            ]);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            if (curl_exec($handle) === false) {
                throw new DeliveryException(true);
            }

            return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseBody);
        } finally {
            curl_close($handle);
        }
    }
}
