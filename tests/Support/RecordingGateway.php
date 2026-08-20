<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Gateway\PleskMailGateway;
use Monolog\Logger;
use PleskX\Api\Client;

final class RecordingGateway extends PleskMailGateway
{
    /** @var string[] */
    public array $calls = [];

    /** @var string[] emails whose mutations should fail */
    public array $failFor = [];

    /** @var array<string, string[]> email => forwarding recipients */
    public array $forwarding = [];

    /** @var array<string, bool> email => autoresponder enabled */
    public array $autoresponders = [];

    /** @var array<string, array<string, mixed>> email => mailbox info (description/password/delete mutate this) */
    public array $mailboxes = [];

    /** @var array<int, array<string, int|string>> */
    public array $domains = [['id' => 1, 'name' => 'company.com']];

    /** @var array<int, array<string, int|string>> */
    public array $mailnames = [['id' => 10, 'name' => 'group', 'description' => 'Group mailbox']];

    /** @var array<string, array<string, mixed>> domain => info for getDomain */
    public array $domainsInfo = [];

    public function __construct(private readonly bool $dryRunMode = false)
    {
        parent::__construct(new Client('fake.local', 8443, 'https'), $this->dryRunMode, new Logger('test'));
    }

    public function setAutoresponder(string $email, string $message, string $endDate): void
    {
        $this->calls[] = $email;
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->autoresponders[$email] = true;
    }

    public function disableAutoresponder(string $email): void
    {
        $this->calls[] = $email;
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->autoresponders[$email] = false;
    }

    public function wasCalledWith(string $email): bool
    {
        return in_array($email, $this->calls, true);
    }

    public function getForwarding(string $email): array
    {
        return $this->forwarding[$email] ?? [];
    }

    public function getAutoresponder(string $email): ?array
    {
        if (!array_key_exists($email, $this->autoresponders)) {
            return null;
        }

        return [
            'enabled' => $this->autoresponders[$email],
            'subject' => '',
            'text' => '',
            'content_type' => 'text/plain',
            'charset' => 'utf-8',
            'end_date' => null,
        ];
    }

    public function addForwardingRecipients(string $email, array $addresses): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        $this->forwarding[$email] = array_values(array_unique(array_merge($this->forwarding[$email] ?? [], $addresses)));
    }

    public function removeForwardingRecipients(string $email, array $addresses): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        $this->forwarding[$email] = array_values(array_diff($this->forwarding[$email] ?? [], $addresses));
    }

    /** @return array<int, array<string, int|string>> */
    public function listDomains(): array
    {
        return $this->domains;
    }

    /** @return array<int, array<string, int|string>> */
    public function listMailnames(int $siteId): array
    {
        return $this->mailnames;
    }

    public function getMailboxInfo(string $email): ?array
    {
        if (!isset($this->mailboxes[$email])) {
            return null;
        }

        return $this->mailboxes[$email];
    }

    public function deleteAddress(string $email): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        unset($this->mailboxes[$email]);
    }

    public function setMailboxDescription(string $email, string $description): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->mailboxes[$email]['description'] = $description;
    }

    public function setMailboxPassword(string $email, string $password): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->mailboxes[$email]['password'] = $password;
    }

    /** @param int[] $siteIds @return array<int, array{site_id: int, id: int, name: string, description: string}> */
    public function listMailnamesBulk(array $siteIds): array
    {
        $rows = [];
        foreach ($siteIds as $siteId) {
            foreach ($this->mailnames as $mailname) {
                $rows[] = [
                    'site_id' => $siteId,
                    'id' => (int) $mailname['id'],
                    'name' => (string) $mailname['name'],
                    'description' => (string) $mailname['description'],
                ];
            }
        }

        return $rows;
    }

    /** @param string[] $emails @return array<string, string[]> */
    public function getForwardingBulk(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $result[$email] = $this->forwarding[$email] ?? [];
        }

        return $result;
    }

    /** @param string[] $emails @return array<string, array<string, mixed>|null> */
    public function getAutoresponderBulk(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $result[$email] = $this->getAutoresponder($email);
        }

        return $result;
    }

    /** @param string[] $emails @return array<string, array<string, mixed>|null> */
    public function getMailboxInfoBulk(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $result[$email] = $this->mailboxes[$email] ?? null;
        }

        return $result;
    }

    public function getDomain(string $domain): ?array
    {
        return $this->domainsInfo[$domain] ?? null;
    }

    public function updateDomain(string $domain, string $description): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($domain, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->domainsInfo[$domain]['description'] = $description;
    }
}
