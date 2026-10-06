<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

/**
 * How a connection authenticates against Linear's GraphQL API.
 *
 * OAuth access tokens are sent as `Authorization: Bearer <token>`, expire and
 * are refreshed. Personal API keys are sent as the raw `Authorization: <key>`
 * header and never expire.
 */
enum LinearAuthMode: string
{
    case OAuth = 'oauth';
    case ApiKey = 'api_key';

    /**
     * The mode selected by `linear.auth_mode`; anything unrecognised means
     * OAuth.
     */
    public static function configured(): self
    {
        $mode = config('linear.auth_mode');

        return is_string($mode) ? (self::tryFrom($mode) ?? self::OAuth) : self::OAuth;
    }
}
