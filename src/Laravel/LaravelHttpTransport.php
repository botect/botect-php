<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Configuration;
use Botect\Contracts\ResponseLimitAwareTransport;
use Botect\Contracts\TimeoutAwareTransport;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class LaravelHttpTransport implements ResponseLimitAwareTransport, TimeoutAwareTransport
{
    public function __construct(private Factory $http, private Configuration $configuration, private int $responseLimit = 262144) {}

    public function withTimeouts(int $connectTimeoutMs, int $timeoutMs): static
    {
        return new self($this->http, $this->configuration->withTimeouts($connectTimeoutMs, $timeoutMs), $this->responseLimit);
    }

    public function withResponseLimit(int $bytes): static
    {
        if ($bytes < 1 || $bytes > 1048576) {
            throw new \InvalidArgumentException('Invalid response limit.');
        }

        return new self($this->http, $this->configuration, $bytes);
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $limit = $this->responseLimit;
        try {
            $request = $this->http->withHeaders($headers)
                ->connectTimeout($this->configuration->connectTimeoutMs / 1000)
                ->timeout($this->configuration->timeoutMs / 1000)
                ->withoutRedirecting()
                ->withOptions(['verify' => true, 'progress' => static function (float $total, float $downloaded) use ($limit): void {
                    if ($downloaded > $limit) {
                        throw new DeliveryException(false);
                    }
                }, 'on_headers' => static function (ResponseInterface $response) use ($limit): void {
                    if ($limit === 1048576 && $response->getStatusCode() !== 200) {
                        $status = $response->getStatusCode();
                        throw new DeliveryException(($status >= 500 && $status <= 599) || in_array($status, [408, 425, 429], true), $status);
                    }
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        throw new DeliveryException(false);
                    }
                }]);
            $response = $request->send($method, $url, ['body' => $body ?? '']);
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $contents = '';
            while (! $stream->eof() && strlen($contents) <= $limit) {
                $chunk = $stream->read(min(8192, $limit + 1 - strlen($contents)));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new DeliveryException(true);
                }
                $contents .= $chunk;
            }
            if (strlen($contents) > $limit) {
                throw new DeliveryException(false);
            }

            return new Response($response->status(), $contents);
        } catch (DeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($limit === 1048576) {
                for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof DeliveryException) {
                        throw $cause;
                    }
                }
            }
            throw new DeliveryException(true, previous: $exception);
        }
    }
}
