<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Parse human-readable byte sizes ("512MB", "2 GB", "1048576") into bytes.
 */
final class HumanSize
{
    private const UNITS = [
        '' => 1,
        'b' => 1,
        'k' => 1024,
        'kb' => 1024,
        'm' => 1024 ** 2,
        'mb' => 1024 ** 2,
        'g' => 1024 ** 3,
        'gb' => 1024 ** 3,
        't' => 1024 ** 4,
        'tb' => 1024 ** 4,
    ];

    public static function toBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \InvalidArgumentException('Byte size must not be empty.');
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (1 !== preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]?b?)$/i', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf('Invalid byte size "%s" (e.g. "512MB", "2GB", "1048576").', $value));
        }

        $bytes = (float) $matches[1] * self::UNITS[strtolower($matches[2])];
        if ($bytes > PHP_INT_MAX) {
            throw new \InvalidArgumentException(sprintf('Byte size "%s" is too large.', $value));
        }

        return (int) $bytes;
    }
}
