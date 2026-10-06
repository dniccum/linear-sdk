<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

final readonly class Member extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @return array{id: string, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
