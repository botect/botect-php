<?php

declare(strict_types=1);

namespace Botect\Http;

use Botect\Configuration;
use Botect\Contracts\ResponseLimitAwareTransport;
use Botect\Contracts\TimeoutAwareTransport;
use Botect\Exceptions\DeliveryException;
use RuntimeException;

final readonly class CurlTransport implements ResponseLimitAwareTransport, TimeoutAwareTransport
{
    public function __construct(private Configuration $configuration, private int $responseLimit = 262144) {}

    public function withTimeouts(int $connectTimeoutMs, int $timeoutMs): static
    {
        return new self($this->configuration->withTimeouts($connectTimeoutMs, $timeoutMs), $this->responseLimit);
    }

    public function withResponseLimit(int $bytes): static
    {
        if ($bytes < 1 || $bytes > 1048576) {
            throw new \InvalidArgumentException('Invalid response limit.');
        }

        return new self($this->configuration, $bytes);
    }

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
        $overflow = false;
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }
        $limit = $this->responseLimit;
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
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$overflow, $limit): int {
                    if (strlen($responseBody) + strlen($chunk) > $limit) {
                        $overflow = true;

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
                if ($overflow && $limit === 1048576) {
                    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    throw new DeliveryException(($status >= 500 && $status <= 599) || in_array($status, [408, 425, 429], true), $status);
                }
                $error = curl_error($handle);

                throw new DeliveryException(true, previous: new RuntimeException($error !== '' ? $error : 'cURL error '.curl_errno($handle)));
            }

            return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseBody);
        } finally {
            curl_close($handle);
        }
    }
}
