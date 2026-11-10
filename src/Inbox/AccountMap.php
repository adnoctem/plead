<?php

declare(strict_types=1);

namespace App\Inbox;

/**
 * Optional `ad-account-map.json` dropped into the inbox directory. It maps
 * AD sAMAccountName values to the real mailbox addresses for environments
 * where the AD `mail` attribute is missing or stale. The file is a control
 * file, not an absence entry: the inbox processor never archives it.
 */
final class AccountMap
{
    public const FILENAME = 'ad-account-map.json';

    /** @param array<string, string> $map lowercased sAMAccountName => email */
    private function __construct(
        private readonly array $map,
        private readonly bool $invalid = false,
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /** A map that exists but could not be parsed; samAccountName entries must fail. */
    public static function invalid(): self
    {
        return new self([], true);
    }

    /**
     * @throws \InvalidArgumentException when the file exists but is not a valid map
     */
    public static function fromDirectory(string $directory): self
    {
        $path = rtrim($directory, '/\\').'/'.self::FILENAME;
        if (!is_file($path)) {
            return self::empty();
        }

        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \InvalidArgumentException(sprintf('Unable to read %s.', self::FILENAME));
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('%s is not valid JSON: %s', self::FILENAME, $e->getMessage()), 0, $e);
        }

        // json_decode turns both {} and [] into an empty PHP array; an empty
        // map is harmless, a non-empty list is not a map.
        if (!is_array($decoded) || ([] !== $decoded && array_is_list($decoded))) {
            throw new \InvalidArgumentException(sprintf(
                '%s must contain a JSON object mapping sAMAccountName to email.',
                self::FILENAME,
            ));
        }

        $map = [];
        foreach ($decoded as $account => $email) {
            if (!is_string($account) || '' === trim($account) || !is_string($email) || '' === trim($email)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s entries must map non-empty sAMAccountName strings to non-empty email strings.',
                    self::FILENAME,
                ));
            }

            $map[strtolower(trim($account))] = trim($email);
        }

        return new self($map);
    }

    public function lookup(?string $samAccountName): ?string
    {
        if (null === $samAccountName) {
            return null;
        }

        return $this->map[strtolower(trim($samAccountName))] ?? null;
    }

    /** Whether the map contains at least one account. */
    public function hasEntries(): bool
    {
        return [] !== $this->map;
    }

    public function isInvalid(): bool
    {
        return $this->invalid;
    }

    /** Whether the file is the account map (and must not be processed as an absence). */
    public static function isMapFile(string $path): bool
    {
        return 0 === strcasecmp(basename($path), self::FILENAME);
    }
}
