<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Models\LinearConnection;

/**
 * The outcome of the OAuth callback: a connection, or a message to show the
 * user.
 */
final readonly class OAuthResult
{
    public function __construct(
        public bool $connected,
        public string $message,
        public ?LinearConnection $connection = null,
    ) {}

    public static function connected(LinearConnection $connection): self
    {
        return new self(true, "Connected to {$connection->organization_name} on Linear.", $connection);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
