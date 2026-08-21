<?php

declare(strict_types=1);

namespace App\Rule;

use App\Util\DateNormalizer;

/**
 * Desired auto-reply of one address, from a mail.autoresponder config entry:
 * message file (rendered as a Twig template), optional start date and a
 * required end date.
 */
final class AutoReplyDefinition
{
    private function __construct(
        private readonly string $email,
        private readonly string $messageFile,
        private readonly ?string $startDate,
        private readonly string $endDate,
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

        $messageFile = trim((string) ($entry['message_file'] ?? ''));
        if ('' === $messageFile) {
            throw new \InvalidArgumentException(sprintf('Autoresponder for "%s" needs a message_file.', $email));
        }

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

        return new self($email, $messageFile, $startDate, $endDate);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function messageFile(): string
    {
        return $this->messageFile;
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
}
