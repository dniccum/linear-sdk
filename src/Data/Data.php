<?php

declare(strict_types=1);

namespace Dniccum\Linear\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Base for data transfer objects that leave the server. toArray() is the wire
 * format.
 *
 * @implements Arrayable<string, mixed>
 */
abstract readonly class Data implements Arrayable, JsonSerializable
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
