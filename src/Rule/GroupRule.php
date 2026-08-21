<?php

declare(strict_types=1);

namespace App\Rule;

/**
 * Desired-state definition of one mail group, from a mail.group config entry:
 * either an exclusion PCRE over the group's domain addresses ("pattern") or a
 * fixed recipient list. The list's own address is never a recipient.
 */
final class GroupRule
{
    /**
     * @param string[] $recipients static recipients, lowercased
     */
    private function __construct(
        private readonly string $email,
        private readonly string $domain,
        private readonly ?string $compiledPattern,
        private readonly array $recipients,
    ) {}

    /**
     * @param array<string, mixed> $entry validated mail.group entry
     */
    public static function fromConfigEntry(array $entry): self
    {
        $address = trim((string) ($entry['address'] ?? ''));
        if ('' === $address) {
            throw new \InvalidArgumentException('Mail group address must not be empty.');
        }

        $domain = null !== ($entry['domain'] ?? null)
            ? strtolower(trim((string) $entry['domain']))
            : null;
        if ('' === (string) $domain) {
            $domain = null;
        }

        if (str_contains($address, '@')) {
            $email = strtolower($address);
            $domain ??= substr($email, strpos($email, '@') + 1);
        } else {
            if (null === $domain) {
                throw new \InvalidArgumentException(sprintf(
                    'Mail group address "%s" has no domain; set "domain" or use a full address.',
                    $address,
                ));
            }
            $email = strtolower($address).'@'.$domain;
        }

        $pattern = null !== ($entry['pattern'] ?? null) ? (string) $entry['pattern'] : null;
        $recipients = array_values(array_unique(array_filter(array_map(
            static fn (string $recipient): string => strtolower(trim($recipient)),
            $entry['recipients'] ?? [],
        ), static fn (string $recipient): bool => '' !== $recipient)));

        if (null !== $pattern && [] !== $recipients) {
            throw new \InvalidArgumentException(sprintf(
                'Mail group "%s" cannot set both pattern and recipients.',
                $email,
            ));
        }
        if (null === $pattern && [] === $recipients) {
            throw new \InvalidArgumentException(sprintf(
                'Mail group "%s" must set either pattern or recipients.',
                $email,
            ));
        }

        return new self($email, $domain, null !== $pattern ? GroupPattern::compile($pattern) : null, $recipients);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /** @return null|string[] static recipients, or null for pattern rules */
    public function recipients(): ?array
    {
        return null === $this->compiledPattern ? $this->recipients : null;
    }

    /**
     * Whether a full address belongs in the group: it must live on the rule's
     * domain and must not match the exclusion pattern.
     */
    public function matches(string $address): bool
    {
        $address = strtolower($address);
        if (!str_ends_with($address, '@'.$this->domain)) {
            return false;
        }

        return null === $this->compiledPattern || 0 === preg_match($this->compiledPattern, $address);
    }
}
