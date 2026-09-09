<?php

declare(strict_types=1);

namespace Botect;

use Botect\Contracts\HttpTransport;
use Botect\Exceptions\DeliveryException;
use Botect\Support\SessionToken;
use Throwable;

final readonly class ApiClient
{
    public function __construct(private Configuration $configuration, private HttpTransport $transport) {}

    /** Blocking worker API. Use Botect::verdict() in the customer request path.
     * @param  array<string, string>  $context
     */
    public function verdict(string $sessionToken, array $context = []): Verdict
    {
        $path = '/sessions/'.SessionToken::validate($sessionToken).'/verdict';
        $query = http_build_query($context, '', '&', PHP_QUERY_RFC3986);
        $data = $this->request('GET', $path.($query === '' ? '' : '?'.$query));
        try {
            return Verdict::fromArray($data);
        } catch (Throwable) {
            throw new DeliveryException(true);
        }
    }

    public function loggedIn(string $sessionToken): void
    {
        $data = $this->request('POST', '/sessions/'.SessionToken::validate($sessionToken).'/logged-in');
        if (($data['logged_in'] ?? null) !== true || ! is_string($data['asserted_at'] ?? null)) {
            throw new DeliveryException(true);
        }
    }

    /** @param array<string, mixed> $payload */
    public function serverIngest(Operation $operation, array $payload, string $idempotencyKey): void
    {
        if (! $this->configuration->serverIngestEnabled || ! in_array($operation, [Operation::RecordPage, Operation::ForwardEvents], true)) {
            throw new DeliveryException(false);
        }
        $path = $operation === Operation::RecordPage ? '/server/page-hits' : '/server/events';
        $this->request('POST', $path, $payload, $idempotencyKey, true);
    }

    /** @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null, bool $emptyResponseAllowed = false): array
    {
        if ($this->configuration->privateKey === null) {
            throw new DeliveryException(false);
        }
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->configuration->privateKey, 'User-Agent' => 'botect-sdk-php/0.1'];
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        try {
            $response = $this->transport->send($method, rtrim($this->configuration->apiUrl, '/').$path, $headers, $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (DeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DeliveryException(true);
        }
        if ($response->status < 200 || $response->status >= 300) {
            $retryable = $response->status >= 500 || in_array($response->status, [408, 425, 429], true)
                || ($response->status === 404 && str_ends_with($path, '/logged-in'));
            throw new DeliveryException($retryable, $response->status);
        }
        if ($emptyResponseAllowed && trim($response->body) === '') {
            return [];
        }
        try {
            $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new DeliveryException(true);
            }

            return $data;
        } catch (Throwable) {
            throw new DeliveryException(true);
        }
    }
}
