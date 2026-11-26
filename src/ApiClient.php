<?php

declare(strict_types=1);

namespace Botect;

use Botect\Contracts\HttpTransport;
use Botect\Contracts\ResponseLimitAwareTransport;
use Botect\Exceptions\DeliveryException;
use Botect\Support\SessionToken;
use InvalidArgumentException;
use Throwable;

final readonly class ApiClient
{
    public function __construct(private Configuration $configuration, private HttpTransport $transport) {}

    /** Blocking HTTP API, used by delivery workers and explicit immediate lookups.
     * @param  array<string, string>  $context
     */
    public function verdict(string $sessionToken, array $context = [], ?bool $loggedIn = null): Verdict
    {
        foreach ($context as $key => $value) {
            if (! in_array($key, ['path', 'ip', 'country', 'ua'], true) || ! is_string($value) || strlen($value) > 2048) {
                throw new InvalidArgumentException('Invalid verdict context.');
            }
        }
        $path = '/sessions/'.SessionToken::validate($sessionToken).'/verdict';
        if ($loggedIn !== null) {
            $context['logged_in'] = $loggedIn ? '1' : '0';
        }
        $query = http_build_query($context, '', '&', PHP_QUERY_RFC3986);
        $data = $this->request('GET', $path.($query === '' ? '' : '?'.$query));
        try {
            return Verdict::fromArray($data);
        } catch (Throwable $exception) {
            throw new DeliveryException(true, previous: $exception);
        }
    }

    /** Fetch raw evidence; status classification precedes JSON decoding. */
    public function challengeList(?string $crawlerDirectoryVersion = null): array
    {
        if ($this->configuration->privateKey === null) {
            throw new DeliveryException(false);
        }
        $transport = $this->transport instanceof ResponseLimitAwareTransport
            ? $this->transport->withResponseLimit(1048576) : $this->transport;
        $query = $crawlerDirectoryVersion === null ? '' : '?'.http_build_query(['crawler_directory_version' => $crawlerDirectoryVersion], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = $transport->send('GET', rtrim($this->configuration->apiUrl, '/').'/enforcement/challenge-list'.$query,
                ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->configuration->privateKey, 'User-Agent' => 'botect-sdk-php/0.1'], null);
        } catch (DeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DeliveryException(true, previous: $exception);
        }
        if ($response->status !== 200) {
            throw new DeliveryException(($response->status >= 500 && $response->status <= 599) || in_array($response->status, [408, 425, 429], true), $response->status);
        }
        try {
            if (strlen($response->body) > 1048576) {
                throw new \UnexpectedValueException('Challenge list too large.');
            }
            $data = json_decode($response->body, false, 32, JSON_THROW_ON_ERROR);
            if (! $data instanceof \stdClass) {
                throw new \UnexpectedValueException('Invalid challenge list.');
            }
            $data = (array) $data;
            if (is_array($data['trusted_ranges'] ?? null)) {
                $data['trusted_ranges'] = array_map(static function ($range): array {
                    if (! $range instanceof \stdClass) {
                        throw new \UnexpectedValueException('Invalid trusted bounds.');
                    }

                    return (array) $range;
                }, $data['trusted_ranges']);
            }

            return $data;
        } catch (Throwable $exception) {
            throw new DeliveryException(false, previous: $exception);
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
        } catch (Throwable $exception) {
            throw new DeliveryException(true, previous: $exception);
        }
        if ($response->status < 200 || $response->status >= 300) {
            $retryable = $response->status >= 500 || in_array($response->status, [408, 425, 429], true)
                || ($response->status === 404 && str_ends_with($path, '/logged-in'));
            $fields = [];
            if ($response->status === 422) {
                $data = json_decode($response->body, false, 32);
                if ($data instanceof \stdClass && ($data->details ?? null) instanceof \stdClass) {
                    $fields = array_keys(get_object_vars($data->details));
                }
            }
            throw new DeliveryException($retryable, $response->status, validationFields: $fields);
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
        } catch (Throwable $exception) {
            throw new DeliveryException(true, previous: $exception);
        }
    }
}
