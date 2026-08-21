<?php

declare(strict_types=1);

namespace App\Rule;

/**
 * PCRE handling for rule patterns. User patterns are written without
 * delimiters (like jq's regex or an editor search), so the pattern is tried
 * as-is first and wrapped in "~...~" when it is not already delimited.
 */
final class GroupPattern
{
    public static function compile(string $pattern): string
    {
        $pattern = trim($pattern);
        if ('' === $pattern) {
            throw new \InvalidArgumentException('Rule pattern must not be empty.');
        }

        if (false !== @preg_match($pattern, '')) {
            return $pattern;
        }

        $wrapped = '~'.$pattern.'~';
        if (false !== @preg_match($wrapped, '')) {
            return $wrapped;
        }

        throw new \InvalidArgumentException(sprintf(
            'Invalid rule pattern "%s": %s',
            $pattern,
            preg_last_error_msg(),
        ));
    }

    public static function compiles(string $pattern): bool
    {
        try {
            self::compile($pattern);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
