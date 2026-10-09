<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

/**
 * Narrowing helpers for decoded JSON, so untyped API payloads can be read
 * without casting `mixed`.
 */
final class Json
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read a nested value with dot notation ("extensions.code"), or the
     * default when any step of the path is missing.
     */
    public static function get(mixed $target, string $path, mixed $default = null): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return $default;
            }

            $target = $target[$segment];
        }

        return $target;
    }

    /**
     * Whether a value is missing, empty or only whitespace.
     */
    public static function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * The associative arrays in a list, dropping anything else.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $rows[] = self::map($item);
            }
        }

        return $rows;
    }

    /**
     * The scalar items of a list, as strings.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        $strings = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }
}
