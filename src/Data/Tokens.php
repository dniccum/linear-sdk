<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Carbon\CarbonImmutable;
use Dniccum\Linear\Support\Json;

/**
 * Tokens returned by Linear's OAuth token endpoint.
 *
 * Deliberately not serializable: tokens must never leave the server.
 */
final readonly class Tokens
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?int $expiresIn,
        public array $scopes,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        $scope = $response['scope'] ?? null;

        $parts = preg_split('/[\s,]+/', Json::string($scope));
        $scopes = is_array($scope) ? Json::strings($scope) : ($parts === false ? [] : $parts);

        return new self(
            accessToken: Json::string($response['access_token'] ?? null),
            refreshToken: Json::nullableString($response['refresh_token'] ?? null),
            expiresIn: Json::integer($response['expires_in'] ?? null),
            scopes: array_values(array_filter($scopes, fn (string $item): bool => $item !== '')),
        );
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresIn === null ? null : CarbonImmutable::now()->addSeconds($this->expiresIn);
    }

    /**
     * The required scopes this grant does not include.
     *
     * @param  list<string>  $required
     * @return list<string>
     */
    public function missingScopes(array $required): array
    {
        return array_values(array_diff($required, $this->scopes));
    }
}
