<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

final readonly class WorkflowState extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
    ) {}

    /**
     * @return array{id: string, name: string, type: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type];
    }
}
