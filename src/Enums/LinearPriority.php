<?php

declare(strict_types=1);

namespace Dniccum\Linear\Enums;

/**
 * Linear's issue priority scale. Linear's API has no endpoint that lists it, so
 * the values live here; they are what `Destination::$priority` and the
 * `priority` field of the HTTP contract carry.
 *
 * Note the order: 0 is "no priority" and 1 is the most urgent.
 */
enum LinearPriority: int
{
    case NoPriority = 0;
    case Urgent = 1;
    case High = 2;
    case Medium = 3;
    case Low = 4;

    public function label(): string
    {
        return match ($this) {
            self::NoPriority => 'No priority',
            self::Urgent => 'Urgent',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    /**
     * Every priority as a value/label pair, in Linear's order, for rendering a
     * select.
     *
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $priority): array => ['value' => $priority->value, 'label' => $priority->label()],
            self::cases(),
        );
    }

    /**
     * The priority for a stored or submitted number; null is "no priority"
     * and so is anything outside 0-4.
     */
    public static function fromNumber(?int $number): self
    {
        return self::tryFrom($number ?? 0) ?? self::NoPriority;
    }
}
