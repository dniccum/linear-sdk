<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

final readonly class Label extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $color,
    ) {}

    /**
     * @return array{id: string, name: string, color: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color];
    }
}
