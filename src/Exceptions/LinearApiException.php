<?php

declare(strict_types=1);

namespace Dniccum\Linear\Exceptions;

use RuntimeException;

/**
 * A failed call to Linear, classified so callers can decide between
 * retrying, asking the user to reconnect, and giving up.
 */
class LinearApiException extends RuntimeException
{
    /** Linear rejected the credentials; the user must reconnect. */
    public const string AUTHENTICATION = 'authentication';

    /** The credentials are valid but lack access to the resource or scope. */
    public const string FORBIDDEN = 'forbidden';

    /** Linear is throttling this workspace; retry after a delay. */
    public const string RATE_LIMITED = 'rate_limited';

    /** Linear refused the input (for example a team that no longer exists). */
    public const string INVALID_REQUEST = 'invalid_request';

    /** Network failure or a Linear-side error; retry after a delay. */
    public const string TRANSIENT = 'transient';

    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function notConnected(): self
    {
        return new self('Linear is not connected. Connect your Linear workspace to continue.', self::AUTHENTICATION);
    }

    public static function notConfigured(): self
    {
        return new self('Linear is not configured. Set the OAuth client credentials or switch to API key mode.', self::INVALID_REQUEST);
    }

    public function isRetryable(): bool
    {
        return in_array($this->reason, [self::RATE_LIMITED, self::TRANSIENT], true);
    }

    public function requiresReconnect(): bool
    {
        return $this->reason === self::AUTHENTICATION;
    }
}
