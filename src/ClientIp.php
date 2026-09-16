<?php

declare(strict_types=1);

namespace Botect;

use InvalidArgumentException;

/**
 * A visitor's address as observed by the customer's server, with a record of
 * how it was found.
 *
 * Only public addresses are representable. A private or reserved address is
 * never the visitor: it is the proxy, the load balancer or the overlay network
 * in front of the application, and reporting it would merge every visitor into
 * one. Callers that read a raw request get null for those and send nothing.
 */
final readonly class ClientIp
{
    /** The framework's own request address, trusted through the application's proxy configuration. */
    public const SOURCE_REQUEST = 'request';

    /** A named header, such as the one a CDN sets to carry the original client address. */
    public const SOURCE_HEADER = 'header';

    /** The application supplied the address itself, through a resolver or an explicit argument. */
    public const SOURCE_RESOLVER = 'resolver';

    /**
     * @param  bool  $inferred  true when the address was guessed by inspecting
     *                          headers the operator never explicitly trusted;
     *                          Botect treats such evidence as weaker
     */
    public function __construct(public string $ip, public string $source, public bool $inferred = false)
    {
        if (! in_array($source, [self::SOURCE_REQUEST, self::SOURCE_HEADER, self::SOURCE_RESOLVER], true)) {
            throw new InvalidArgumentException('Unknown client IP source.');
        }
    }

    /** Validates, rejects private and reserved ranges, and canonicalises (IPv6 compression, mapped forms). */
    public static function fromString(?string $ip, string $source, bool $inferred = false): ?self
    {
        $ip = trim((string) $ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        return new self(inet_ntop($packed), $source, $inferred);
    }

    /** @return array{observed_ip: string, observed_ip_source: string, observed_ip_inferred: bool} */
    public function toPayload(): array
    {
        return ['observed_ip' => $this->ip, 'observed_ip_source' => $this->source, 'observed_ip_inferred' => $this->inferred];
    }
}
