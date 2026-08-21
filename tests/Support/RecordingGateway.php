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

    /** @var array<string, string[]> email => alias addresses */
    public array $aliases = [];

    /** @var array<string, string> email => new local part after rename */
    public array $renames = [];

    /** @var array<string, mixed> server info returned by getServerInfo */
    public array $serverInfo = [];

    /** @var array<string, string> admin info returned by getAdminInfo */
    public array $adminInfo = [];

    /** @var array<int, array<string, string>> sessions returned by listSessions */
    public array $sessions = [];

    /** @var string[] session ids terminated */
    public array $terminatedSessions = [];

    /** @var array<string, int> domain => status set */
    public array $domainStatuses = [];

    /** @var array<int, array<string, string>> service states returned by listServiceStates */
    public array $serviceStates = [];

    /** @var string[] service operations performed, "op:service" */
    public array $serviceOps = [];

    /** @var array<int, array<string, string>> IPs returned by listIps */
    public array $ips = [];

    /** @var string[] IPs added */
    public array $addedIps = [];

    /** @var string[] IPs removed */
    public array $removedIps = [];

    /** @var array<string, array<string, string>> ip => properties set */
    public array $ipSets = [];

    /** @var array<int, array<string, string>> components returned by listComponents */
    public array $components = [];

    /** @var array<string, array{component_id: string, update_id: string}> installed components */
    public array $installedComponents = [];

    /** @var array<int, array<string, string>> sites added: [name, htype, parent, description] */
    public array $addedSites = [];

    /** @var string[] sites removed */
    public array $removedSites = [];

    /** @var array<string, array<int, array<string, string>>> domain => traffic rows for getSiteTraffic */
    public array $traffic = [];

    /** @var array<string, array{date: string, counters: array<string, int>}> domain => traffic set */
    public array $trafficSets = [];

    /** @var array<string, array<int, array<string, string>>> domain => descriptor properties */
    public array $descriptors = [];

    /** @var array<int, array{id: string, name: string, version: string, release: string, active: bool}> extensions returned by listExtensions */
    public array $extensions = [];

    /** @var string[] extensions installed (id or url) */
    public array $installedExtensions = [];

    /** @var string[] extensions uninstalled */
    public array $uninstalledExtensions = [];

    /** @var array<string, array{id: string, operation: string, params: array<string, string>}> extension calls */
    public array $extensionCalls = [];

    /** @var string[] CLI command ids returned by cliCommands */
    public array $cliCommands = [];

    /** @var array<string, array<string, mixed>> id => reference returned by cliRef */
    public array $cliRefs = [];

    /** @var array<int, array{id: string, args: string[], failOnError: bool}> cli calls recorded */
    public array $cliCalls = [];

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

    /** @return string[] */
    public function getAliases(string $email): array
    {
        return $this->aliases[$email] ?? [];
    }

    public function addAliases(string $email, array $aliases): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        $this->aliases[$email] = array_values(array_unique(array_merge($this->aliases[$email] ?? [], $aliases)));
    }

    public function removeAliases(string $email, array $aliases): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        $this->aliases[$email] = array_values(array_diff($this->aliases[$email] ?? [], $aliases));
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, string[]>
     */
    public function getAliasesBulk(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $result[$email] = $this->aliases[$email] ?? [];
        }

        return $result;
    }

    public function renameAddress(string $email, string $newName): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->renames[$email] = $newName;
    }

    /** @param array<string, string> $properties */
    public function setMailboxProperties(string $email, array $properties): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->mailboxes[$email] = array_merge($this->mailboxes[$email] ?? [], $properties);
    }

    public function setMailboxQuota(string $email, int $quotaBytes): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->mailboxes[$email]['quota'] = $quotaBytes;
    }

    /** @return array<string, mixed> */
    public function getServerInfo(): array
    {
        return $this->serverInfo;
    }

    /** @return array<int, array<string, string>> */
    public function listSessions(): array
    {
        return $this->sessions;
    }

    public function terminateSession(string $sessionId): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($sessionId, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->terminatedSessions[] = $sessionId;
    }

    public function setDomainStatus(string $domain, int $status): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($domain, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->domainStatuses[$domain] = $status;
    }

    /** @return array<string, string> */
    public function getAdminInfo(): array
    {
        return $this->adminInfo;
    }

    /** @return array<int, array<string, string>> */
    public function listServiceStates(): array
    {
        return $this->serviceStates;
    }

    public function manageService(string $service, string $operation): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($service, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->serviceOps[] = $operation.':'.$service;
    }

    /** @return array<int, array<string, string>> */
    public function listIps(): array
    {
        return $this->ips;
    }

    public function addIp(string $ipAddress, string $netmask, string $type, string $interface): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($ipAddress, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->addedIps[] = $ipAddress;
    }

    public function removeIp(string $ipAddress): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($ipAddress, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->removedIps[] = $ipAddress;
    }

    /** @param array<string, string> $properties */
    public function setIp(string $ipAddress, array $properties): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($ipAddress, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->ipSets[$ipAddress] = $properties;
    }

    /** @return array<int, array<string, string>> */
    public function listComponents(): array
    {
        return $this->components;
    }

    public function installComponent(string $componentId, string $updateId): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($componentId, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->installedComponents[$componentId] = [
            'component_id' => $componentId,
            'update_id' => $updateId,
        ];
    }

    /** @param array<string, string> $vrtProperties */
    public function addSite(string $name, string $htype, ?string $webspaceName, ?string $description, array $vrtProperties, ?string $destUrl): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($name, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->addedSites[] = [
            'name' => $name,
            'htype' => $htype,
            'parent' => $webspaceName ?? '',
            'description' => $description ?? '',
        ];
    }

    public function removeSite(string $name): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($name, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->removedSites[] = $name;
    }

    /** @return array<int, array<string, string>> */
    public function getSiteTraffic(string $name, ?string $since, ?string $to): array
    {
        return $this->traffic[$name] ?? [];
    }

    /** @param array<string, int> $counters */
    public function setSiteTraffic(string $domain, string $date, array $counters): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($domain, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->trafficSets[$domain] = ['date' => $date, 'counters' => $counters];
    }

    /** @return array<int, array<string, string>> */
    public function getHostingDescriptor(string $name): array
    {
        return $this->descriptors[$name] ?? [];
    }

    /** @return array<int, array{id: string, name: string, version: string, release: string, active: bool}> */
    public function listExtensions(): array
    {
        return $this->extensions;
    }

    /** @return null|array{id: string, name: string, version: string, release: string, active: bool} */
    public function getExtension(string $id): ?array
    {
        foreach ($this->extensions as $extension) {
            if ($extension['id'] === $id) {
                return $extension;
            }
        }

        return null;
    }

    public function installExtension(?string $id, ?string $url): void
    {
        if ($this->dryRunMode) {
            return;
        }
        $target = $id ?? (string) $url;
        if (in_array($target, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->installedExtensions[] = $target;
    }

    public function uninstallExtension(string $id): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($id, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->uninstalledExtensions[] = $id;
    }

    /** @param array<string, string> $params */
    public function callExtension(string $id, string $operation, array $params = []): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($id, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->extensionCalls[$id] = ['id' => $id, 'operation' => $operation, 'params' => $params];
    }

    /** @return string[] */
    public function cliCommands(): array
    {
        return $this->cliCommands;
    }

    /** @return array<string, mixed> */
    public function cliRef(string $id): array
    {
        return $this->cliRefs[$id] ?? [];
    }

    /**
     * @param string[] $params
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function cliCall(string $id, array $params, bool $failOnError = true): array
    {
        $this->cliCalls[] = ['id' => $id, 'args' => $params, 'failOnError' => $failOnError];

        if ($this->dryRunMode) {
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if (in_array($id, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        return ['code' => 0, 'stdout' => 'done: '.implode(' ', $params), 'stderr' => ''];
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

        // Match the real gateway's contract: every key the commands read is
        // always present, even when a test seeds only the relevant subset.
        return $this->mailboxes[$email] + [
            'mailbox_quota' => 0,
            'mailbox_usage' => 0,
            'antivir' => '',
            'outgoing_messages_mbox_limit' => '',
        ];
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

    /**
     * @param string[] $emails
     *
     * @return array<string, string[]>
     */
    public function getForwardingBulk(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $result[$email] = $this->forwarding[$email] ?? [];
        }

        return $result;
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, null|array<string, mixed>>
     */
    public function getAutoresponderBulk(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $result[$email] = $this->getAutoresponder($email);
        }

        return $result;
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, null|array<string, mixed>>
     */
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

    public function setSiteType(string $domain, string $htype, ?string $destUrl, array $properties = []): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($domain, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
        $this->domainsInfo[$domain]['htype'] = $htype;
        if (null !== $destUrl) {
            $this->domainsInfo[$domain]['dest_url'] = $destUrl;
        }
        if ([] !== $properties) {
            $this->domainsInfo[$domain]['properties'] = $properties;
        }
    }
}
