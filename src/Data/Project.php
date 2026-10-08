<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Emoji;
use Dniccum\Linear\Support\Json;

final readonly class Project extends Data
{
    /**
     * @param  ?string  $color  Hex colour such as "#5e6ad2".
     * @param  ?string  $icon  An emoji or a decorative icon identifier, never a URL.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $color = null,
        public ?string $icon = null,
    ) {}

    /**
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(
            Json::string($node['id'] ?? null),
            Json::string($node['name'] ?? null),
            Json::nullableString($node['color'] ?? null),
            Json::nullableString($node['icon'] ?? null),
        );
    }

    /**
     * Whether `icon` is an emoji, as opposed to the name of one of Linear's
     * built-in icons. Either way it is never a URL.
     */
    public function iconIsEmoji(): bool
    {
        return Emoji::is($this->icon);
    }

    /**
     * @return array{id: string, name: string, color: ?string, icon: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color, 'icon' => $this->icon];
    }
}
