<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;

/**
 * The link that lets a user leave the configuration page: its text and an
 * absolute URL (or root-relative path). `null` in the payload when the link is
 * disabled or its destination cannot be resolved.
 */
final readonly class BackData extends Data
{
    public function __construct(
        public string $label,
        public string $url,
    ) {}

    /**
     * @return array{label: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
        ];
    }
}
