<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Dniccum\Linear\Support\Json;

final readonly class Member extends Data
{
    /**
     * @param  ?string  $avatarUrl  An http(s) URL on Linear's avatar host; null when the member has no photo.
     * @param  ?string  $initials  Up to a few letters for the avatar fallback.
     * @param  ?string  $avatarBackgroundColor  Hex colour behind the initials.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatarUrl = null,
        public ?string $initials = null,
        public ?string $avatarBackgroundColor = null,
    ) {}

    /**
     * Read a `User` node. The display name wins over the full name.
     *
     * @param  array<string, mixed>  $node
     */
    public static function fromArray(array $node): self
    {
        return new self(
            Json::string($node['id'] ?? null),
            Json::nullableString($node['displayName'] ?? null) ?? Json::string($node['name'] ?? null),
            Json::nullableString($node['avatarUrl'] ?? null),
            Json::nullableString($node['initials'] ?? null),
            Json::nullableString($node['avatarBackgroundColor'] ?? null),
        );
    }

    /**
     * The initials to draw when there is no photo (or it fails to load):
     * Linear's own, otherwise the first letters of the first two words of the
     * name, otherwise "?".
     */
    public function displayInitials(): string
    {
        if ($this->initials !== null) {
            return $this->initials;
        }

        $words = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = implode('', array_map(
            fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($words === false ? [] : $words, 0, 2),
        ));

        return $initials === '' ? '?' : mb_strtoupper($initials);
    }

    /**
     * @return array{id: string, name: string, avatarUrl: ?string, initials: ?string, avatarBackgroundColor: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatarUrl' => $this->avatarUrl,
            'initials' => $this->initials,
            'avatarBackgroundColor' => $this->avatarBackgroundColor,
        ];
    }
}
