<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use JsonSerializable;

/**
 * Base for data transfer objects that leave the server. toArray() is the wire
 * format.
 */
abstract readonly class Data implements JsonSerializable
{
    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
