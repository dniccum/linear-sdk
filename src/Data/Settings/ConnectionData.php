<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Dniccum\Linear\Models\LinearConnection;

/**
 * Client-safe view of a Linear connection: workspace details and health,
 * never credentials.
 */
final readonly class ConnectionData extends Data
{
    public function __construct(
        public LinearConnectionStatus $status,
        public ?string $organizationName,
        public ?string $organizationUrlKey,
        public ?string $userName,
        public ?string $userEmail,
        public ?string $lastError,
        public ?string $lastSyncedAt,
    ) {}

    public static function fromModel(LinearConnection $connection): self
    {
        return new self(
            status: $connection->status,
            organizationName: $connection->organization_name,
            organizationUrlKey: $connection->organization_url_key,
            userName: $connection->linear_user_name,
            userEmail: $connection->linear_user_email,
            lastError: $connection->last_error,
            lastSyncedAt: $connection->last_synced_at?->toIso8601String(),
        );
    }

    /**
     * @return array{status: string, organizationName: ?string, organizationUrlKey: ?string, userName: ?string, userEmail: ?string, lastError: ?string, lastSyncedAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'organizationName' => $this->organizationName,
            'organizationUrlKey' => $this->organizationUrlKey,
            'userName' => $this->userName,
            'userEmail' => $this->userEmail,
            'lastError' => $this->lastError,
            'lastSyncedAt' => $this->lastSyncedAt,
        ];
    }
}
