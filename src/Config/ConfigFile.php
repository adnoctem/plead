<?php

declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class ConfigFile
{
    /** @param string[] $candidates */
    public static function targetFile(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /** @return array<string, mixed> */
    public static function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        if (str_ends_with(strtolower($file), '.json')) {
            return json_decode((string) file_get_contents($file), true) ?: [];
        }

        return Yaml::parse((string) file_get_contents($file)) ?: [];
    }

    /** @param array<string, mixed> $data */
    public static function write(string $target, array $data): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create config directory: %s', $directory));
        }

        if (str_ends_with(strtolower($target), '.json')) {
            file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } else {
            file_put_contents($target, Yaml::dump($data, 8, 4));
        }
    }

    /**
     * Add or replace a mail.group entry in the general mail section of the
     * config file. Entries are matched by their composed address, so an
     * existing domain-less entry ("address: all" + "domain: x") is replaced
     * by the full-address form as well.
     *
     * @param array<string, mixed> $entry validated entry: address + pattern/recipients
     */
    public static function upsertMailGroup(string $target, string $email, array $entry): void
    {
        $email = strtolower($email);
        $data = self::read($target);
        $groups = $data['mail']['group'] ?? [];
        if (!is_array($groups)) {
            $groups = [];
        }

        $replaced = false;
        foreach ($groups as $index => $existing) {
            if (!is_array($existing) || self::composedAddress($existing) !== $email) {
                continue;
            }
            $groups[$index] = $entry;
            $replaced = true;

            break;
        }
        if (!$replaced) {
            $groups[] = $entry;
        }

        $data['mail']['group'] = $groups;
        self::write($target, $data);
    }

    /**
     * Add or replace a mail.autoresponder entry in the general mail section of
     * the config file, matched by address.
     *
     * @param array<string, mixed> $entry validated entry: address + message_file + dates
     */
    public static function upsertMailAutoresponder(string $target, string $email, array $entry): void
    {
        $email = strtolower($email);
        $data = self::read($target);
        $entries = $data['mail']['autoresponder'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $replaced = false;
        foreach ($entries as $index => $existing) {
            if (!is_array($existing) || strtolower((string) ($existing['address'] ?? '')) !== $email) {
                continue;
            }
            $entries[$index] = $entry;
            $replaced = true;

            break;
        }
        if (!$replaced) {
            $entries[] = $entry;
        }

        $data['mail']['autoresponder'] = $entries;
        self::write($target, $data);
    }

    /** Remove the mail.autoresponder entry of an address from the config file. */
    public static function removeMailAutoresponder(string $target, string $email): void
    {
        $email = strtolower($email);
        $data = self::read($target);
        $entries = $data['mail']['autoresponder'] ?? [];
        if (!is_array($entries)) {
            return;
        }

        $kept = [];
        foreach ($entries as $existing) {
            if (!is_array($existing) || strtolower((string) ($existing['address'] ?? '')) !== $email) {
                $kept[] = $existing;
            }
        }
        if (count($kept) === count($entries)) {
            return;
        }

        if ([] === $kept) {
            unset($data['mail']['autoresponder']);
            if (isset($data['mail']) && [] === $data['mail']) {
                unset($data['mail']);
            }
        } else {
            $data['mail']['autoresponder'] = $kept;
        }
        self::write($target, $data);
    }

    /** @param array<string, mixed> $entry */
    private static function composedAddress(array $entry): string
    {
        $address = strtolower((string) ($entry['address'] ?? ''));
        if (str_contains($address, '@')) {
            return $address;
        }

        return $address.'@'.strtolower((string) ($entry['domain'] ?? ''));
    }
}
