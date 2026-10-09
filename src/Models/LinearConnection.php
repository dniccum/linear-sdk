<?php

declare(strict_types=1);

namespace Dniccum\Linear\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dniccum\Linear\Contracts\Connection;
use Dniccum\Linear\Data\Tokens;
use Dniccum\Linear\Database\Factories\LinearConnectionFactory;
use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Enums\LinearConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An owner's authorization of a single Linear workspace, by OAuth or by
 * personal API key.
 *
 * Credentials are encrypted at rest and hidden from serialization so they can
 * never leak into a JSON response, even if the relation is eager loaded. For
 * an API key connection `access_token` holds the key, and there is no refresh
 * token or expiry.
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property LinearAuthMode $auth_type
 * @property string $linear_organization_id
 * @property string $organization_name
 * @property string|null $organization_url_key
 * @property string $linear_user_id
 * @property string|null $linear_user_name
 * @property string|null $linear_user_email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property CarbonInterface|null $token_expires_at
 * @property list<string>|null $scopes
 * @property LinearConnectionStatus $status
 * @property string|null $last_error
 * @property CarbonInterface|null $last_error_at
 * @property CarbonInterface|null $last_synced_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $owner
 */
class LinearConnection extends Model implements Connection
{
    /** @use HasFactory<LinearConnectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'auth_type',
        'linear_organization_id',
        'organization_name',
        'organization_url_key',
        'linear_user_id',
        'linear_user_name',
        'linear_user_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'status',
        'last_error',
        'last_error_at',
        'last_synced_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'auth_type' => 'oauth',
        'status' => 'active',
    ];

    public function getTable(): string
    {
        return config()->string('linear.table_prefix', 'linear_').'connections';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function connectionId(): int|string
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    public function organizationId(): string
    {
        return $this->linear_organization_id;
    }

    public function authMode(): LinearAuthMode
    {
        return $this->auth_type;
    }

    public function accessToken(): string
    {
        return $this->access_token;
    }

    public function refreshToken(): ?string
    {
        return $this->refresh_token;
    }

    public function reload(): void
    {
        $this->refresh();
    }

    public function storeTokens(Tokens $tokens): void
    {
        $this->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $this->refresh_token,
            'token_expires_at' => $tokens->expiresAt(CarbonImmutable::now()),
            'status' => LinearConnectionStatus::Active,
        ])->save();
    }

    public function isActive(): bool
    {
        return $this->status === LinearConnectionStatus::Active;
    }

    public function usesApiKey(): bool
    {
        return $this->auth_type === LinearAuthMode::ApiKey;
    }

    /**
     * Whether the access token is expired or close enough to expiry that a
     * request made with it could be rejected mid-flight. API keys never
     * expire.
     */
    public function tokenExpiresSoon(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->lte(now()->addMinutes(5));
    }

    /**
     * Record a successful write to Linear, clearing any earlier failure.
     */
    public function markSynced(): void
    {
        $this->forceFill([
            'last_synced_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'last_error' => $message,
            'last_error_at' => now(),
        ])->save();
    }

    public function markNeedsReconnect(string $message): void
    {
        $this->forceFill([
            'status' => LinearConnectionStatus::NeedsReconnect,
            'last_error' => $message,
            'last_error_at' => now(),
        ])->save();
    }

    protected static function newFactory(): LinearConnectionFactory
    {
        return LinearConnectionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_type' => LinearAuthMode::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'status' => LinearConnectionStatus::class,
            'last_error_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
