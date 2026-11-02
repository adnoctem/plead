<?php

declare(strict_types=1);

namespace App\Inbox;

/**
 * Resolved `watch.inbox` configuration. The inbox is enabled only when a
 * directory is configured; everything else has a default.
 *
 * Timezone: Plesk's XML and REST APIs expose no server timezone, so the
 * operator has to align `watch.inbox.timezone` (or the process TZ) with the
 * Plesk server - naive date/time values in inbox files are interpreted in it.
 */
final class InboxSettings
{
    public const DEFAULT_TEMPLATE_FORWARDING = 'resources/templates/autoresponder-forwarding.txt.twig';

    public function __construct(
        public readonly string $directory,
        public readonly string $processedDirectory,
        public readonly string $failedDirectory,
        public readonly ?string $domain,
        public readonly \DateTimeZone $timezone,
        public readonly ?string $dataFile,
        public readonly ?string $templateAutoreply,
        public readonly string $templateForwarding,
        public readonly ?string $language,
        public readonly ?string $subject,
        public readonly ?string $format,
        public readonly bool $inclusive,
    ) {}

    /**
     * @param array<string, mixed> $config the merged configuration
     *
     * @return null|self null when `watch.inbox.path` is not configured
     */
    public static function fromConfig(array $config): ?self
    {
        $watch = $config['watch'] ?? [];
        $inbox = is_array($watch) ? ($watch['inbox'] ?? []) : [];
        if (!is_array($inbox)) {
            return null;
        }

        $directory = trim((string) ($inbox['path'] ?? ''));
        if ('' === $directory) {
            return null;
        }

        $timezoneName = trim((string) ($inbox['timezone'] ?? ''));

        try {
            $timezone = new \DateTimeZone('' === $timezoneName ? date_default_timezone_get() : $timezoneName);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf(
                'watch.inbox.timezone "%s" is not a valid IANA timezone name.',
                $timezoneName,
            ), 0, $e);
        }

        $templateAutoreply = trim((string) ($inbox['template_autoreply'] ?? ''));
        $templateForwarding = trim((string) ($inbox['template_forwarding'] ?? ''));

        $directory = rtrim($directory, '/\\');

        return new self(
            $directory,
            self::subdirectory($directory, (string) ($inbox['processed_path'] ?? ''), 'processed'),
            self::subdirectory($directory, (string) ($inbox['failed_path'] ?? ''), 'failed'),
            self::nullable($inbox['domain'] ?? null),
            $timezone,
            self::nullable($inbox['data_file'] ?? null),
            '' === $templateAutoreply ? null : $templateAutoreply,
            '' === $templateForwarding ? self::DEFAULT_TEMPLATE_FORWARDING : $templateForwarding,
            self::nullable($inbox['language'] ?? null),
            self::nullable($inbox['subject'] ?? null),
            self::nullable($inbox['format'] ?? null),
            (bool) ($inbox['inclusive'] ?? true),
        );
    }

    /** Relative archive locations are resolved against the inbox directory. */
    private static function subdirectory(string $directory, string $configured, string $default): string
    {
        $configured = trim($configured);
        if ('' === $configured) {
            return rtrim($directory, '/\\').'/'.$default;
        }

        if (str_starts_with($configured, '/') || str_starts_with($configured, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)) {
            return rtrim($configured, '/\\');
        }

        return rtrim($directory, '/\\').'/'.trim($configured, '/\\');
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return '' === $value ? null : $value;
    }
}
