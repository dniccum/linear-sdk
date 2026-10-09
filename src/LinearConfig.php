<?php

declare(strict_types=1);

namespace Dniccum\Linear;

use Dniccum\Linear\Enums\LinearAuthMode;
use Dniccum\Linear\Support\Json;

/**
 * The settings the framework-agnostic core needs, as one immutable value.
 *
 * Laravel builds it from `config/linear.php`; anywhere else, build it from
 * your own configuration with {@see self::fromArray()} (which reads the same
 * keys as the config file) or with the constructor.
 */
final readonly class LinearConfig
{
    public const string DEFAULT_API_URL = 'https://api.linear.app';

    public const string DEFAULT_AUTHORIZE_URL = 'https://linear.app/oauth/authorize';

    /**
     * The scopes a grant must include.
     *
     * @var list<string>
     */
    public const array DEFAULT_SCOPES = ['read', 'issues:create', 'comments:create'];

    /**
     * @param  list<string>  $scopes
     * @param  string  $onUpdate  What an update of an already linked record does: "ignore" or "comment".
     * @param  string  $onDelete  What deleting an already linked record does: "ignore" or "comment".
     */
    public function __construct(
        public LinearAuthMode $authMode = LinearAuthMode::OAuth,
        public ?string $clientId = null,
        public ?string $clientSecret = null,
        public ?string $redirectUri = null,
        public array $scopes = self::DEFAULT_SCOPES,
        public string $apiUrl = self::DEFAULT_API_URL,
        public string $authorizeUrl = self::DEFAULT_AUTHORIZE_URL,
        public string $onUpdate = 'ignore',
        public string $onDelete = 'ignore',
    ) {}

    /**
     * Build from an array that uses the keys of `config/linear.php`
     * (`auth_mode`, `client_id`, `client_secret`, `redirect`, `scopes`,
     * `api_url`, `authorize_url`, `on_update`, `on_delete`). Anything missing
     * or blank falls back to its default (an empty `scopes` list stays empty).
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $mode = $config['auth_mode'] ?? null;

        return new self(
            authMode: $mode instanceof LinearAuthMode ? $mode : (is_string($mode) ? LinearAuthMode::tryFrom($mode) : null) ?? LinearAuthMode::OAuth,
            clientId: Json::nullableString($config['client_id'] ?? null),
            clientSecret: Json::nullableString($config['client_secret'] ?? null),
            redirectUri: Json::nullableString($config['redirect'] ?? null),
            scopes: array_key_exists('scopes', $config) ? Json::strings($config['scopes']) : self::DEFAULT_SCOPES,
            apiUrl: Json::nullableString($config['api_url'] ?? null) ?? self::DEFAULT_API_URL,
            authorizeUrl: Json::nullableString($config['authorize_url'] ?? null) ?? self::DEFAULT_AUTHORIZE_URL,
            onUpdate: Json::string($config['on_update'] ?? null, 'ignore'),
            onDelete: Json::string($config['on_delete'] ?? null, 'ignore'),
        );
    }

    /**
     * Whether the OAuth client credentials are set.
     */
    public function hasOAuthCredentials(): bool
    {
        return ! Json::blank($this->clientId) && ! Json::blank($this->clientSecret);
    }

    /**
     * An absolute URL on the Linear API.
     */
    public function apiEndpoint(string $path): string
    {
        return rtrim($this->apiUrl, '/').$path;
    }
}
