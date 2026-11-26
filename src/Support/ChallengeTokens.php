<?php

declare(strict_types=1);

namespace Botect\Support;

use Botect\ClientIp;
use Botect\Configuration;
use Botect\Page;
use InvalidArgumentException;
use Throwable;

final readonly class ChallengeTokens
{
    public function __construct(private Configuration $configuration) {}

    public static function returnTarget(string $target): string
    {
        $decoded = rawurldecode($target);
        if (preg_match('/[\x00-\x20\x7f]/', $target) || strlen($target) > 2048 || ! str_starts_with($target, '/') || str_starts_with($decoded, '//')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) || str_contains($target, '#')
            || parse_url($target, PHP_URL_HOST) !== null || parse_url($target) === false) {
            throw new InvalidArgumentException('Invalid challenge return target.');
        }

        return $target;
    }

    public function issue(Page $page, ClientIp $ip, string $userAgent, string $origin, string $returnTo, string $source = 'crowd', ?int $now = null): array
    {
        if (! in_array($source, ['crowd', 'verdict'], true)) {
            throw new InvalidArgumentException('Invalid challenge source.');
        }
        $now ??= time();
        $nonce = self::encode(random_bytes(32));
        $data = ['v' => 1, 'nonce' => $nonce, 'iat' => $now, 'exp' => $now + 120, 'session' => SessionToken::validate($page->sessionToken),
            'binding' => $this->binding($ip, $userAgent, $origin, false), 'return_to' => self::returnTarget($returnTo), 'source' => $source];

        return ['ticket' => $this->token('ticket', $data), 'nonce' => $nonce, 'return_to' => $data['return_to']];
    }

    public function verifyTicket(string $ticket, string $session, ClientIp $ip, string $userAgent, string $origin, ?int $now = null): ?array
    {
        try {
            $data = $this->read('ticket', $ticket, 120, $now);
            if ($data === null || ($data['session'] ?? null) !== $session || ! is_string($data['binding'] ?? null)
                || ! hash_equals($this->binding($ip, $userAgent, $origin, false), $data['binding'])
                || ! is_string($data['nonce'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $data['nonce'])
                || ! is_string($data['return_to'] ?? null) || ! in_array($data['source'] ?? null, ['crowd', 'verdict'], true)) {
                return null;
            }
            self::returnTarget($data['return_to']);

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /** Cookie value only; the host sets HttpOnly, host-only, /, SameSite=Lax and Secure on HTTPS. */
    public function clearance(string $session, ClientIp $ip, string $userAgent, string $origin, ?int $now = null): string
    {
        $now ??= time();

        return $this->token('clearance', ['v' => 1, 'session' => SessionToken::validate($session), 'binding' => $this->binding($ip, $userAgent, $origin, true),
            'iat' => $now, 'exp' => $now + $this->configuration->clearanceSeconds]);
    }

    public function hasClearance(string $cookie, string $session, ClientIp $ip, string $userAgent, string $origin, ?int $now = null): bool
    {
        try {
            $data = $this->read('clearance', $cookie, $this->configuration->clearanceSeconds, $now);

            return $data !== null && ($data['session'] ?? null) === $session && is_string($data['binding'] ?? null)
                && hash_equals($this->binding($ip, $userAgent, $origin, true), $data['binding']);
        } catch (Throwable) {
            return false;
        }
    }

    private function binding(ClientIp $ip, string $ua, string $origin, bool $network): string
    {
        if ($ip->inferred || ! IpRangeSet::publicAddress($ip->ip)) {
            throw new InvalidArgumentException('A trustworthy public client address is required.');
        }
        $parts = parse_url($origin);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid challenge origin.');
        }
        $address = $network ? IpRangeSet::networkPrefix($ip->ip) : bin2hex(IpRangeSet::address($ip->ip));

        return $this->sign('binding', json_encode([$address, hash('sha256', $ua), $origin, $this->configuration->enforcementScope()], JSON_THROW_ON_ERROR));
    }

    private function token(string $purpose, array $data): string
    {
        $body = self::encode(json_encode($data, JSON_THROW_ON_ERROR));

        return $body.'.'.$this->sign($purpose, $body);
    }

    private function read(string $purpose, string $token, int $lifetime, ?int $now): ?array
    {
        if (strlen($token) > 4096 || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts)
            || ! hash_equals($this->sign($purpose, $parts[1]), $parts[2])) {
            return null;
        }
        $body = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = json_decode($body ?: '', true, 8, JSON_THROW_ON_ERROR);
        $now ??= time();
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_int($data['iat'] ?? null) || ! is_int($data['exp'] ?? null)
            || $data['iat'] > $now + 30 || $data['exp'] <= $now || $lifetime !== $data['exp'] - $data['iat']) {
            return null;
        }

        return $data;
    }

    private function sign(string $purpose, string $body): string
    {
        if ($this->configuration->privateKey === null) {
            throw new InvalidArgumentException('Challenge signing requires a private key.');
        }

        return hash_hmac('sha256', 'botect:js-v1:'.$purpose.':'.$this->configuration->enforcementScope().':'.$body, $this->configuration->privateKey);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
