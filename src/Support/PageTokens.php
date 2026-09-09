<?php

declare(strict_types=1);

namespace Botect\Support;

use Botect\Configuration;
use Botect\Page;
use InvalidArgumentException;
use Throwable;

final readonly class PageTokens
{
    public function __construct(private Configuration $configuration) {}

    public function mint(?string $cookie = null, ?int $now = null): Page
    {
        $now ??= time();
        $session = $cookie === null ? null : $this->readCookie($cookie);
        $hadCookie = $session !== null;
        $session ??= SessionToken::generate();
        $id = bin2hex(random_bytes(16));
        $body = self::encode(json_encode(['v' => 1, 'id' => $id, 'session' => $session, 'iat' => $now, 'exp' => $now + $this->configuration->pageTokenTtl], JSON_THROW_ON_ERROR));

        return new Page($id, $session, $body.'.'.$this->sign('page', $body), $now, $hadCookie);
    }

    public function verify(string $token, ?int $now = null): ?Page
    {
        try {
            if (strlen($token) > 1024 || ! preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $parts) || ! hash_equals($this->sign('page', $parts[1]), $parts[2])) {
                return null;
            }
            $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
            $data = json_decode($json ?: '', true, 8, JSON_THROW_ON_ERROR);
            $now ??= time();
            if (($data['v'] ?? null) !== 1 || ! is_int($data['iat'] ?? null) || ! is_int($data['exp'] ?? null)
                || $data['iat'] > $now + 30 || $data['exp'] <= $now || $this->configuration->pageTokenTtl !== $data['exp'] - $data['iat']
                || ! is_string($data['id'] ?? null) || ! preg_match('/^[a-f0-9]{32}$/D', $data['id'])) {
                return null;
            }

            return new Page($data['id'], SessionToken::validate($data['session']), $token, $data['iat']);
        } catch (Throwable) {
            return null;
        }
    }

    public function cookie(Page $page): string
    {
        return $page->sessionToken.'.'.$this->sign('session', $page->sessionToken);
    }

    public function readCookie(string $cookie): ?string
    {
        try {
            if (strlen($cookie) > 193 || ! preg_match('/^([A-Za-z0-9_-]{1,128})\.([a-f0-9]{64})$/D', $cookie, $parts)) {
                return null;
            }

            return hash_equals($this->sign('session', $parts[1]), $parts[2]) ? $parts[1] : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sign(string $purpose, string $value): string
    {
        if ($this->configuration->privateKey === null) {
            throw new InvalidArgumentException('A private key is required to mint page tokens.');
        }

        return hash_hmac('sha256', 'botect:'.$purpose.':'.$this->configuration->siteKey.':'.$value, $this->configuration->privateKey);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
