<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

/**
 * Client-generated identifiers. Linear accepts an ID on creation, which is
 * what lets a retry look the record up instead of creating it twice.
 */
final class Uuid
{
    /**
     * A random (version 4) UUID.
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
