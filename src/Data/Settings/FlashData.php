<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;

/**
 * The one-off status and error messages flashed by the OAuth, API key and
 * disconnect endpoints.
 */
final readonly class FlashData extends Data
{
    public const string STATUS_KEY = 'linear_status';

    public const string ERROR_KEY = 'linear_error';

    public function __construct(
        public ?string $status,
        public ?string $error,
    ) {}

    /**
     * @return array{status: ?string, error: ?string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'error' => $this->error];
    }
}
