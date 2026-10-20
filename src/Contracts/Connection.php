<?php

declare(strict_types=1);

namespace Dniccum\Linear\Contracts;

use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Enums\LinearAuthMode;

/**
 * An owner's authorization of one Linear workspace: the credentials the API
 * client sends and the health of the link.
 *
 * Implement it on whatever you persist connections with (an Eloquent model, a
 * Doctrine entity). Every `mark*()` and `storeTokens()` call persists
 * immediately. Credentials should be encrypted at rest.
 */
interface Connection
{
    public function connectionId(): int|string;

    /**
     * The Linear workspace this connection is authorized for.
     */
    public function organizationId(): string;

    public function authMode(): LinearAuthMode;

    /**
     * The OAuth access token, or the personal API key.
     */
    public function accessToken(): string;

    public function refreshToken(): ?string;

    /**
     * Whether the connection can be used (not flagged for reconnection).
     */
    public function isActive(): bool;

    public function usesApiKey(): bool;

    /**
     * Whether the access token is expired or close enough to expiry that a
     * request made with it could be rejected mid-flight. API keys never
     * expire.
     */
    public function tokenExpiresSoon(): bool;

    /**
     * Re-read the credentials from storage, picking up a refresh another
     * worker did.
     */
    public function reload(): void;

    /**
     * Store freshly refreshed tokens and mark the connection active. A grant
     * without a new refresh token keeps the old one.
     */
    public function storeTokens(Tokens $tokens): void;

    /**
     * Record a successful write to Linear, clearing any earlier failure.
     */
    public function markSynced(): void;

    public function markFailed(string $message): void;

    /**
     * Linear rejected the credentials: flag the connection so the user is
     * asked to reconnect.
     */
    public function markNeedsReconnect(string $message): void;
}
