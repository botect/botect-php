<?php

declare(strict_types=1);

namespace Botect;

use Botect\Support\SessionToken;
use InvalidArgumentException;

final readonly class Delivery
{
    /** @param array<string, mixed> $payload */
    public function __construct(public Operation $operation, public string $sessionToken, public array $payload, public string $id, public int $createdAt)
    {
        SessionToken::validate($sessionToken);
        if (! preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new InvalidArgumentException('Invalid delivery ID.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function make(Operation $operation, string $sessionToken, array $payload = [], ?string $deduplicationKey = null): self
    {
        return new self($operation, $sessionToken, $payload, $deduplicationKey === null ? bin2hex(random_bytes(32)) : hash('sha256', $operation->value.'|'.$deduplicationKey), time());
    }

    /** @return array{operation:string, session_token:string, payload:array<string,mixed>, id:string, created_at:int} */
    public function toArray(): array
    {
        return ['operation' => $this->operation->value, 'session_token' => $this->sessionToken, 'payload' => $this->payload, 'id' => $this->id, 'created_at' => $this->createdAt];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Operation::from($data['operation']), $data['session_token'], $data['payload'], $data['id'], $data['created_at']);
    }
}
