<?php

declare(strict_types=1);

namespace Botect\Laravel;

use Botect\Configuration;
use Botect\Contracts\TimeoutAwareTransport;
use Botect\Exceptions\DeliveryException;
use Botect\Http\Response;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class LaravelHttpTransport implements TimeoutAwareTransport
{
    public function __construct(private Factory $http, private Configuration $configuration) {}

    public function withTimeouts(int $connectTimeoutMs, int $timeoutMs): static
    {
        return new self($this->http, $this->configuration->withTimeouts($connectTimeoutMs, $timeoutMs));
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        try {
            $request = $this->http->withHeaders($headers)
                ->connectTimeout($this->configuration->connectTimeoutMs / 1000)
                ->timeout($this->configuration->timeoutMs / 1000)
                ->withoutRedirecting()
                ->withOptions(['verify' => true, 'progress' => static function (float $total, float $downloaded): void {
                    if ($downloaded > 262144) {
                        throw new DeliveryException(false);
                    }
                }, 'on_headers' => static function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 262144) {
                        throw new DeliveryException(false);
                    }
                }]);
            $response = $request->send($method, $url, ['body' => $body ?? '']);
            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $contents = '';
            while (! $stream->eof() && strlen($contents) <= 262144) {
                $chunk = $stream->read(min(8192, 262145 - strlen($contents)));
                if ($chunk === '' && ! $stream->eof()) {
                    throw new DeliveryException(true);
                }
                $contents .= $chunk;
            }
            if (strlen($contents) > 262144) {
                throw new DeliveryException(false);
            }

            return new Response($response->status(), $contents);
        } catch (DeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DeliveryException(true, previous: $exception);
        }
    }
}
