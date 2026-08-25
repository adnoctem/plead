<?php

declare(strict_types=1);

namespace App\Rule;

use App\Util\DateNormalizer;

/**
 * Desired auto-reply of one address, from a mail.autoresponder config entry:
 * optional JSON/YAML data file for template variables, optional start date,
 * subject and format, and a required end date.
 */
final class AutoReplyDefinition
{
    private function __construct(
        private readonly string $email,
        private readonly ?string $dataFile,
        private readonly ?string $startDate,
        private readonly string $endDate,
        private readonly ?bool $inclusive,
        private readonly ?string $subject,
        private readonly ?string $format,
        private readonly ?string $language,
    ) {}

    /**
     * @param array<string, mixed> $entry validated mail.autoresponder entry
     */
    public static function fromConfigEntry(array $entry): self
    {
        $email = strtolower(trim((string) ($entry['address'] ?? '')));
        if ('' === $email || !str_contains($email, '@')) {
            throw new \InvalidArgumentException(sprintf(
                'Autoresponder address "%s" must be a full email address.',
                (string) ($entry['address'] ?? ''),
            ));
        }

        $dataFile = trim((string) ($entry['data_file'] ?? ''));
        if ('' !== $dataFile && (!is_file($dataFile) || !is_readable($dataFile))) {
            throw new \InvalidArgumentException(sprintf(
                'Autoresponder data_file "%s" for "%s" is not a readable file.',
                $dataFile,
                $email,
            ));
        }
        $dataFile = '' === $dataFile ? null : $dataFile;

        $startDate = null !== ($entry['start_date'] ?? null)
            ? DateNormalizer::coerce($entry['start_date'])
            : null;
        $endDate = DateNormalizer::coerce($entry['end_date'] ?? '');
        if (null !== $startDate && new \DateTimeImmutable($endDate) <= new \DateTimeImmutable($startDate)) {
            throw new \InvalidArgumentException(sprintf(
                'Autoresponder end_date must be after start_date for "%s".',
                $email,
            ));
        }

        $subject = trim((string) ($entry['subject'] ?? ''));
        $format = trim((string) ($entry['format'] ?? ''));
        if ('' !== $format && !in_array($format, ['plain', 'html'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Autoresponder format for "%s" must be "plain" or "html", got "%s".',
                $email,
                $format,
            ));
        }

        $language = trim((string) ($entry['language'] ?? ''));

        return new self(
            $email,
            $dataFile,
            $startDate,
            $endDate,
            isset($entry['inclusive']) ? (bool) $entry['inclusive'] : null,
            '' === $subject ? null : $subject,
            '' === $format ? null : $format,
            '' === $language ? null : $language,
        );
    }

    public function email(): string
    {
        return $this->email;
    }

    /** @return null|string JSON/YAML file with template data, null = none */
    public function dataFile(): ?string
    {
        return $this->dataFile;
    }

    /** @return null|string normalized ISO 8601 start date, null = as soon as possible */
    public function startDate(): ?string
    {
        return $this->startDate;
    }

    public function endDate(): string
    {
        return $this->endDate;
    }

    /** @return null|bool whether the end date counts as vacation, null = data file decides */
    public function inclusive(): ?bool
    {
        return $this->inclusive;
    }

    /** @return null|string reply subject, null = Plesk default (Re: <request_subject>) */
    public function subject(): ?string
    {
        return $this->subject;
    }

    /** @return null|string 'plain'|'html', null = server default */
    public function format(): ?string
    {
        return $this->format;
    }

    /** @return null|string render language (catalog locale), null = first catalog language */
    public function language(): ?string
    {
        return $this->language;
    }
}
