<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Replaces credential values in a resolved configuration tree. Commands never
 * print secrets; operators read the loaded configuration file when they need
 * the actual value.
 */
final class SecretMasker
{
    private const SECRET_KEYS = ['secret_key', 'password'];

    public static function isSecretKey(string $key): bool
    {
        return in_array(strtolower($key), self::SECRET_KEYS, true);
    }

    public static function mask(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                if (null !== $child && '' !== $child) {
                    $value[$key] = '***';
                }

                continue;
            }

            $value[$key] = self::mask($child);
        }

        return $value;
    }
}
