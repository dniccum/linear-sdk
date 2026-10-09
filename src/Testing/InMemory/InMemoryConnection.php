<?php

declare(strict_types=1);

namespace Dniccum\Linear\Testing\InMemory;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Enums\LinearAuthMode;

/**
 * A {@see Connection} that lives in memory. Handy in tests, and a compact
 * example of what your own (Doctrine, PDO, ...) implementation has to do.
 */
final class InMemoryConnection implements Connection
{
    public bool $active = true;

    public ?string $lastError = null;

    public bool $synced = false;

    public function __construct(
        public readonly int|string $id,
        public readonly string $organization = 'org-1',
        public string $token = 'token',
        public ?string $refresh = null,
        public ?DateTimeImmutable $expiresAt = null,
        public readonly LinearAuthMode $mode = LinearAuthMode::OAuth,
    ) {}

    public function connectionId(): int|string
    {
        return $this->id;
    }

    public function organizationId(): string
    {
        return $this->organization;
    }

    public function authMode(): LinearAuthMode
    {
        return $this->mode;
    }

    public function accessToken(): string
    {
        return $this->token;
    }

    public function refreshToken(): ?string
    {
        return $this->refresh;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function usesApiKey(): bool
    {
        return $this->mode === LinearAuthMode::ApiKey;
    }

    public function tokenExpiresSoon(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= CarbonImmutable::now()->addMinutes(5);
    }

    public function reload(): void
    {
        // Nothing is stored elsewhere.
    }

    public function storeTokens(Tokens $tokens): void
    {
        $this->token = $tokens->accessToken;
        $this->refresh = $tokens->refreshToken ?? $this->refresh;
        $this->expiresAt = $tokens->expiresAt();
        $this->active = true;
    }

    public function markSynced(): void
    {
        $this->synced = true;
        $this->lastError = null;
    }

    public function markFailed(string $message): void
    {
        $this->lastError = $message;
    }

    public function markNeedsReconnect(string $message): void
    {
        $this->active = false;
        $this->lastError = $message;
    }
}
