<?php

declare(strict_types=1);

namespace App\Util;

final class DateNormalizer
{
    /**
     * Normalize any acceptable CLI date input to ISO 8601 with explicit UTC
     * offset. Naive inputs (no offset) are interpreted in the server's local
     * timezone, so the stored value always carries a real offset.
     */
    public static function normalize(string $input): string
    {
        $value = trim($input);
        if ('' === $value) {
            throw new \InvalidArgumentException('Empty date value');
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Invalid date: "%s"', $input), 0, $e);
        }

        return $date->format(\DateTimeInterface::ATOM);
    }

    public static function now(): string
    {
        return new \DateTimeImmutable()->format(\DateTimeInterface::ATOM);
    }

    /**
     * Normalize a config-file date value to ISO 8601. Symfony's YAML parser
     * turns unquoted ISO dates into Unix timestamps (ints) and some formats
     * into DateTime objects; the CLI passes plain strings.
     */
    public static function coerce(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_int($value)) {
            return new \DateTimeImmutable('@'.$value)->format(\DateTimeInterface::ATOM);
        }

        return self::normalize((string) $value);
    }
}
