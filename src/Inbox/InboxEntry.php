<?php

declare(strict_types=1);

namespace App\Inbox;

/**
 * One validated absence entry from an inbox JSON file (schema v1).
 *
 * The format is intentionally not AD-specific: `email` addresses a mailbox
 * directly, while `samAccountName` (+ `domain`) is a convenience for
 * directory-driven generators. Unknown keys are ignored for forward
 * compatibility; anything contradicting the schema throws an
 * \InvalidArgumentException, which the processor turns into a move to
 * failed/.
 */
final class InboxEntry
{
    public const SCHEMA_VERSION = 1;
    public const ACTION_SCHEDULE = 'schedule';
    public const ACTION_CANCEL = 'cancel';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/';
    private const EMAIL_PATTERN = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';
    private const ACCOUNT_PATTERN = '/^[A-Za-z0-9._-]+$/';
    private const DATA_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private function __construct(
        public readonly string $action,
        public readonly string $email,
        public readonly ?string $samAccountName,
        /** @var null|string ISO 8601 with offset; null for cancel entries */
        public readonly ?string $startDate,
        /** @var null|string ISO 8601 with offset; null for cancel entries */
        public readonly ?string $endDate,
        public readonly ?string $deputyEmail,
        public readonly ?string $language,
        public readonly ?string $subject,
        public readonly ?string $format,
        public readonly ?bool $inclusive,
        /** @var array<string, mixed> */
        public readonly array $data,
        public readonly ?string $submittedAt,
    ) {}

    /**
     * @param array<string, mixed> $payload         decoded JSON object
     * @param null|string          $defaultDomain   domain appended to a bare samAccountName
     * @param \DateTimeZone        $defaultTimezone timezone for naive date/time values
     *
     * @throws \InvalidArgumentException when the payload violates the schema
     */
    public static function fromPayload(array $payload, ?string $defaultDomain, \DateTimeZone $defaultTimezone): self
    {
        self::validateSchemaVersion($payload);

        $action = self::string($payload, 'action') ?? self::ACTION_SCHEDULE;
        if (!in_array($action, [self::ACTION_SCHEDULE, self::ACTION_CANCEL], true)) {
            throw new \InvalidArgumentException(sprintf(
                'action must be "%s" or "%s", got "%s".',
                self::ACTION_SCHEDULE,
                self::ACTION_CANCEL,
                $action,
            ));
        }

        $samAccountName = self::string($payload, 'samAccountName');
        $email = self::resolveEmail($payload, $samAccountName, $defaultDomain);
        $submittedAt = self::submittedAt($payload);

        if (self::ACTION_CANCEL === $action) {
            return new self($action, $email, $samAccountName, null, null, null, null, null, null, null, [], $submittedAt);
        }

        $deputyEmail = self::deputyEmail($payload, $email);
        $timezone = self::timezone($payload, $defaultTimezone);

        [$startDate, $endDate] = self::schedule($payload, $timezone);

        return new self(
            $action,
            $email,
            $samAccountName,
            $startDate,
            $endDate,
            $deputyEmail,
            self::string($payload, 'language'),
            self::string($payload, 'subject'),
            self::format($payload),
            self::inclusive($payload),
            self::data($payload),
            $submittedAt,
        );
    }

    /** @param array<string, mixed> $payload */
    private static function validateSchemaVersion(array $payload): void
    {
        $value = $payload['schemaVersion'] ?? self::SCHEMA_VERSION;
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('schemaVersion must be an integer.');
        }

        if (self::SCHEMA_VERSION !== (int) $value) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported schemaVersion %d (this build understands version %d).',
                (int) $value,
                self::SCHEMA_VERSION,
            ));
        }
    }

    /** @param array<string, mixed> $payload */
    private static function resolveEmail(array $payload, ?string $samAccountName, ?string $defaultDomain): string
    {
        $email = self::string($payload, 'email');
        if (null !== $email) {
            return self::normalizeEmail($email);
        }

        if (null === $samAccountName) {
            throw new \InvalidArgumentException('either "email" or "samAccountName" is required.');
        }

        if (!preg_match(self::ACCOUNT_PATTERN, $samAccountName)) {
            throw new \InvalidArgumentException(sprintf(
                'samAccountName "%s" contains unsupported characters (allowed: letters, digits, ".", "_", "-").',
                $samAccountName,
            ));
        }

        $domain = self::string($payload, 'domain') ?? $defaultDomain;
        if (null === $domain || '' === trim($domain)) {
            throw new \InvalidArgumentException(
                'samAccountName requires a "domain" on the entry (or watch.inbox.domain in the config).',
            );
        }

        return self::normalizeEmail($samAccountName.'@'.trim($domain));
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!preg_match(self::EMAIL_PATTERN, $email)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid email address.', $email));
        }

        return $email;
    }

    /** @param array<string, mixed> $payload */
    private static function deputyEmail(array $payload, string $email): ?string
    {
        $deputy = self::string($payload, 'deputyEmail');
        if (null === $deputy) {
            return null;
        }

        $deputy = self::normalizeEmail($deputy);
        if ($deputy === $email) {
            throw new \InvalidArgumentException('deputyEmail must differ from the absent mailbox.');
        }

        return $deputy;
    }

    /** @param array<string, mixed> $payload */
    private static function timezone(array $payload, \DateTimeZone $default): \DateTimeZone
    {
        $name = self::string($payload, 'timezone');
        if (null === $name) {
            return $default;
        }

        try {
            return new \DateTimeZone($name);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('timezone "%s" is not a valid IANA timezone name.', $name), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string} start and end as ISO 8601 with offset
     */
    private static function schedule(array $payload, \DateTimeZone $timezone): array
    {
        $startDate = self::requiredDate($payload, 'startDate');
        $endDate = self::requiredDate($payload, 'endDate');
        $startTime = self::time($payload, 'startTime') ?? '00:00:00';
        $endTime = self::time($payload, 'endTime') ?? '23:59:59';

        $start = self::moment($startDate, $startTime, $timezone);
        $end = self::moment($endDate, $endTime, $timezone);

        if ($end <= $start) {
            throw new \InvalidArgumentException(sprintf(
                'the absence ends (%s) before it starts (%s).',
                $end->format(\DateTimeInterface::ATOM),
                $start->format(\DateTimeInterface::ATOM),
            ));
        }

        return [$start->format(\DateTimeInterface::ATOM), $end->format(\DateTimeInterface::ATOM)];
    }

    /** @param array<string, mixed> $payload */
    private static function requiredDate(array $payload, string $key): string
    {
        $date = self::string($payload, $key);
        if (null === $date || !preg_match(self::DATE_PATTERN, $date)) {
            throw new \InvalidArgumentException(sprintf('%s is required as a "YYYY-MM-DD" string.', $key));
        }

        $year = (int) substr($date, 0, 4);
        $month = (int) substr($date, 5, 2);
        $day = (int) substr($date, 8, 2);
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException(sprintf('%s "%s" is not a real calendar date.', $key, $date));
        }

        return $date;
    }

    /** @param array<string, mixed> $payload */
    private static function time(array $payload, string $key): ?string
    {
        $value = self::string($payload, $key);
        if (null === $value) {
            return null;
        }

        if (!preg_match(self::TIME_PATTERN, $value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a "HH:mm" (or "HH:mm:ss") string, got "%s".', $key, $value));
        }

        return 5 === strlen($value) ? $value.':00' : $value;
    }

    private static function moment(string $date, string $time, \DateTimeZone $timezone): \DateTimeImmutable
    {
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date.' '.$time, $timezone);
        if (false === $moment) {
            throw new \InvalidArgumentException(sprintf('invalid date/time "%s %s".', $date, $time));
        }

        return $moment;
    }

    /** @param array<string, mixed> $payload */
    private static function format(array $payload): ?string
    {
        $format = self::string($payload, 'format');
        if (null === $format) {
            return null;
        }

        if (!in_array($format, ['plain', 'html'], true)) {
            throw new \InvalidArgumentException(sprintf('format must be "plain" or "html", got "%s".', $format));
        }

        return $format;
    }

    /** @param array<string, mixed> $payload */
    private static function inclusive(array $payload): ?bool
    {
        if (!array_key_exists('inclusive', $payload) || null === $payload['inclusive']) {
            return null;
        }

        if (!is_bool($payload['inclusive'])) {
            throw new \InvalidArgumentException('inclusive must be a boolean.');
        }

        return $payload['inclusive'];
    }

    /**
     * Arbitrary template variables merged over the configured baseline data
     * file. Values are scalars (or locale maps of scalars), so templates can
     * resolve per-language data as key[lang] ?? key.de ?? key.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function data(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            throw new \InvalidArgumentException('data must be an object.');
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || !preg_match(self::DATA_KEY_PATTERN, $key)) {
                throw new \InvalidArgumentException(sprintf(
                    'data key "%s" is not a valid template variable name.',
                    (string) $key,
                ));
            }

            if (null === $value || is_scalar($value)) {
                continue;
            }

            if (!is_array($value)) {
                throw new \InvalidArgumentException(sprintf('data.%s must be a scalar or a locale map.', $key));
            }

            foreach ($value as $locale => $translated) {
                if (!is_string($locale) || !is_scalar($translated)) {
                    throw new \InvalidArgumentException(sprintf('data.%s must map locale names to scalar values.', $key));
                }
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private static function submittedAt(array $payload): ?string
    {
        $value = self::string($payload, 'submittedAt');
        if (null === $value) {
            return null;
        }

        try {
            new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('submittedAt "%s" is not a valid ISO 8601 timestamp.', $value), 0, $e);
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || null === $payload[$key]) {
            return null;
        }

        if (!is_string($payload[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be a string.', $key));
        }

        $value = trim($payload[$key]);

        return '' === $value ? null : $value;
    }
}
