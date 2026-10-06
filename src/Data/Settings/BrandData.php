<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data\Settings;

use Dniccum\Linear\Data\Data;
use Dniccum\Linear\Support\Json;

/**
 * How the configuration page is branded: `linear.brand`.
 */
final readonly class BrandData extends Data
{
    public const string DEFAULT_NAME = 'Linear';

    public const string DEFAULT_COLOR = '#5E6AD2';

    public function __construct(
        public string $name,
        public ?string $logo,
        public string $color,
    ) {}

    /**
     * Read `linear.brand`, falling back to the defaults for anything blank.
     * The colour must be a CSS hex colour: it ends up in a style attribute.
     */
    public static function fromConfig(): self
    {
        $brand = Json::map(config('linear.brand'));
        $color = Json::string($brand['color'] ?? null);

        return new self(
            name: Json::nullableString($brand['name'] ?? null) ?? self::DEFAULT_NAME,
            logo: Json::nullableString($brand['logo'] ?? null),
            color: preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1 ? $color : self::DEFAULT_COLOR,
        );
    }

    /**
     * @return array{name: string, logo: ?string, color: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'logo' => $this->logo, 'color' => $this->color];
    }
}
