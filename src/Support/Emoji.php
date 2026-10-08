<?php

declare(strict_types=1);

namespace Dniccum\Linear\Support;

/**
 * Tells emoji apart from the icon names Linear also uses in its `icon` fields.
 */
final class Emoji
{
    /**
     * Blocks that hold pictographs, flags (regional indicators) and the
     * symbols that have emoji presentation. Spelled out as ranges so it does
     * not depend on the PCRE build supporting Extended_Pictographic.
     */
    private const string START = '/^(?:[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{25A0}-\x{25FF}\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{3030}\x{303D}\x{3297}\x{3299}])/u';

    /**
     * Whether the value starts with an emoji. Icon names such as "Bug" or
     * "Rocket", shortcodes, digits and empty strings are not emoji.
     */
    public static function is(?string $value): bool
    {
        return $value !== null && preg_match(self::START, $value) === 1;
    }
}
