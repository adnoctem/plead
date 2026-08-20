<?php

declare(strict_types=1);

namespace App\Gateway;

use PleskX\Api\Client;
use PleskX\Api\Exception;
use PleskX\Api\XmlResponse;
use Psr\Log\LoggerInterface;

class PleskMailGateway
{
    /** @var array<string, int> site-id cache keyed by domain */
    private array $siteIdCache = [];

    public function __construct(
        private readonly Client $client,
        private readonly bool $dryRun,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return null|array{
     *     enabled: bool,
     *     subject: string,
     *     text: string,
     *     content_type: string,
     *     charset: string,
     *     end_date: null|string,
     * }
     */
    public function getAutoresponder(string $email): ?array
    {
        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $get = $packet->addChild('mail')->addChild('get_info');
        $filter = $get->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $filter->addChild('name', $name);
        $get->addChild('autoresponder');

        $response = $this->requestOrNull($packet, $email);
        if (null === $response) {
            return null;
        }

        $autoresponders = $response->xpath('//result/mailname/autoresponder');

        return [] === $autoresponders ? null : $this->autoresponderFromNode($autoresponders[0]);
    }

    public function setAutoresponder(string $email, string $message, string $endDate): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would call mail update set_autoresponder for {email} (end_date {end_date})', [
                'email' => $email,
                'end_date' => $this->toPleskDate($endDate),
            ]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);
        $pleskEndDate = $this->toPleskDate($endDate);

        $packet = $this->client->getPacket();
        $update = $packet->addChild('mail')->addChild('update');
        $set = $update->addChild('set');
        $filter = $set->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        $autoresponder = $mailname->addChild('autoresponder');
        $autoresponder->addChild('enabled', 'true');
        $autoresponder->addChild('text', $message);
        $autoresponder->addChild('end_date', $pleskEndDate);

        $this->client->request($packet);

        $this->logger->info('Set autoresponder for {email} (end_date {end_date})', [
            'email' => $email,
            'end_date' => $pleskEndDate,
        ]);
    }

    public function disableAutoresponder(string $email): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would disable autoresponder for {email}', ['email' => $email]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $update = $packet->addChild('mail')->addChild('update');
        $set = $update->addChild('set');
        $filter = $set->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        $mailname->addChild('autoresponder')->addChild('enabled', 'false');

        $this->client->request($packet);

        $this->logger->info('Disabled autoresponder for {email}', ['email' => $email]);
    }

    /**
     * Enumerate every domain on the server. Some Plesk setups (Obsidian with
     * per-domain subscriptions) return no results for an unfiltered site-get;
     * the domains live at the webspace level, and the main site of each
     * webspace shares its id. Both shapes were validated against a real
     * server (XML API 1.6.9.1).
     *
     * @return array<int, array<string, int|string>>
     */
    public function listDomains(): array
    {
        $domains = $this->listWebspaces();
        if ([] === $domains) {
            $domains = $this->listSites();
        }

        // Main-site ids equal webspace ids, so seed the site-id cache from the
        // enumeration and skip a per-domain site-get in later lookups.
        foreach ($domains as $domain) {
            $this->siteIdCache[(string) $domain['name']] = (int) $domain['id'];
        }

        return $domains;
    }

    /** @return array<int, array<string, int|string>> */
    public function listMailnames(int $siteId): array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('mail')->addChild('get_info');
        $get->addChild('filter')->addChild('site-id', (string) $siteId);

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $mailnames = [];
        foreach ($response->xpath('//result/mailname') as $mailname) {
            $mailnames[] = [
                'id' => (int) $mailname->id,
                'name' => (string) $mailname->name,
                'description' => (string) $mailname->description,
            ];
        }

        return $mailnames;
    }

    public function domainNameForSite(int $siteId): string
    {
        // listDomains() seeds the site-id cache, so the reverse lookup is a
        // pure map lookup in the common case.
        $name = array_search($siteId, $this->siteIdCache, true);
        if (false !== $name) {
            return (string) $name;
        }

        foreach ($this->listDomains() as $domain) {
            if ((int) $domain['id'] === $siteId) {
                return (string) $domain['name'];
            }
        }

        throw new \RuntimeException(sprintf('Unable to resolve site id %d to a domain name.', $siteId));
    }

    /**
     * Fetch everything about one domain. webspace-get is used because the
     * enumeration (listDomains) is webspace-based on this server; on other
     * setups domains resolve through the site fallback instead.
     *
     * @return null|array<string, mixed> null when the domain does not exist
     */
    public function getDomain(string $domain): ?array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('webspace')->addChild('get');
        $get->addChild('filter')->addChild('name', $domain);
        $dataset = $get->addChild('dataset');
        $dataset->addChild('gen_info');
        $dataset->addChild('hosting');
        $dataset->addChild('limits');
        $dataset->addChild('prefs');

        $response = $this->requestOrNull($packet, $domain);
        if (null === $response) {
            return null;
        }

        $results = $response->xpath('//result');
        if ([] === $results) {
            return null;
        }

        $data = $results[0]->data ?? null;
        if (null === $data || !isset($data->gen_info)) {
            return null;
        }

        $genInfo = $data->gen_info;

        $info = [
            'id' => (int) $results[0]->id,
            'name' => (string) $genInfo->name,
            'ascii_name' => (string) $genInfo->{'ascii-name'},
            'status' => (string) $genInfo->status,            // '0' = enabled, '16' = disabled
            'htype' => (string) $genInfo->htype,              // vrt_hst | std_fwd | ...
            'cr_date' => (string) $genInfo->cr_date,
            'real_size' => (string) $genInfo->real_size,
            'owner_login' => (string) $genInfo->{'owner-login'},
            'ip_addresses' => array_values(array_map(
                static fn ($node): string => (string) $node,
                $genInfo->xpath('dns_ip_address'),
            )),
            'guid' => (string) $genInfo->guid,
            'external_id' => (string) $genInfo->{'external-id'},
            'description' => (string) $genInfo->description,
            'admin_description' => (string) $genInfo->{'admin-description'},
        ];

        if (isset($data->hosting->vrt_hst)) {
            $vrt = $data->hosting->vrt_hst;
            $info['hosting'] = [
                'ftp_login' => (string) $vrt->{'ftp-login'},
                'www_root' => (string) $vrt->{'www-root'},
                'home' => (string) $vrt->home,
                'ip_address' => (string) $vrt->{'ip-address'},
                'php' => (string) $vrt->php,
            ];
        }

        // limits/prefs carry many optional fields; collect whatever the
        // server reports as a flat map instead of enumerating each field.
        foreach (['limits', 'prefs'] as $section) {
            if (isset($data->{$section})) {
                foreach ($data->{$section}->children() as $key => $value) {
                    $info[$section][(string) $key] = (string) $value;
                }
            }
        }

        return $info;
    }

    public function updateDomain(string $domain, string $description): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would update description of domain {domain}', ['domain' => $domain]);

            return;
        }

        // Shape derived from the plesk library's Webspace::setProperties;
        // pending live validation.
        $packet = $this->client->getPacket();
        $set = $packet->addChild('webspace')->addChild('set');
        $set->addChild('filter')->addChild('name', $domain);
        $set->addChild('values')->addChild('gen_setup')->addChild('description', $description);

        $this->client->request($packet);

        $this->logger->info('Updated description of domain {domain}', ['domain' => $domain]);
    }

    /**
     * The Plesk API has no cross-domain mail listing, but it accepts many
     * operations in a single HTTP request. These bulk methods batch all
     * per-domain/per-address queries into ONE round trip and merge the
     * results, so listing commands stay fast no matter how many domains the
     * server hosts.
     *
     * @param int[] $siteIds
     *
     * @return array<int, array{site_id: int, id: int, name: string, description: string}>
     */
    public function listMailnamesBulk(array $siteIds): array
    {
        if ([] === $siteIds) {
            return [];
        }

        $requests = [];
        foreach ($siteIds as $siteId) {
            $requests[] = ['mail' => ['get_info' => ['filter' => ['site-id' => (string) $siteId]]]];
        }

        $rows = [];
        foreach ($this->batchQueries($requests) as $index => $response) {
            $siteId = $siteIds[$index];
            foreach ($this->okResults($response, sprintf('mail get_info for site %d', $siteId)) as $result) {
                foreach ($result->mailname as $mailname) {
                    $rows[] = [
                        'site_id' => $siteId,
                        'id' => (int) $mailname->id,
                        'name' => (string) $mailname->name,
                        'description' => (string) $mailname->description,
                    ];
                }
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
        $result = $this->bulkRead($emails, 'forwarding', static function (\SimpleXMLElement $mailname): array {
            return array_values(array_map(
                static fn ($node): string => (string) $node,
                $mailname->xpath('forwarding/address'),
            ));
        });

        // Missing mailnames surface as null; forwarding reads report [].
        return array_map(static fn ($value): array => $value ?? [], $result);
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, string[]>
     */
    public function getAliasesBulk(array $emails): array
    {
        $result = $this->bulkRead($emails, 'aliases', static function (\SimpleXMLElement $mailname): array {
            return array_values(array_map(
                static fn ($node): string => (string) $node,
                $mailname->xpath('alias'),
            ));
        });

        // Missing mailnames surface as null; alias reads report [].
        return array_map(static fn ($value): array => $value ?? [], $result);
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, null|array<string, mixed>>
     */
    public function getAutoresponderBulk(array $emails): array
    {
        return $this->bulkRead($emails, 'autoresponder', function (\SimpleXMLElement $mailname): ?array {
            $autoresponders = $mailname->xpath('autoresponder');

            return [] === $autoresponders ? null : $this->autoresponderFromNode($autoresponders[0]);
        });
    }

    /**
     * @param string[] $emails
     *
     * @return array<string, null|array<string, mixed>>
     */
    public function getMailboxInfoBulk(array $emails): array
    {
        return $this->bulkRead($emails, ['mailbox', 'mailbox-usage', 'forwarding', 'autoresponder'], function (\SimpleXMLElement $mailname): array {
            return $this->mailboxInfoFromNode($mailname);
        });
    }

    /**
     * @return null|array{
     *     name: string,
     *     description: string,
     *     mailbox_enabled: bool,
     *     mailbox_quota: int,
     *     mailbox_usage: int,
     *     forwarding: string[],
     *     autoresponder_enabled: bool,
     *     antivir: string,
     *     outgoing_messages_mbox_limit: string,
     * }
     */
    public function getMailboxInfo(string $email): ?array
    {
        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $get = $packet->addChild('mail')->addChild('get_info');
        $filter = $get->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $filter->addChild('name', $name);
        $get->addChild('mailbox');
        $get->addChild('mailbox-usage');
        $get->addChild('forwarding');
        $get->addChild('autoresponder');

        $response = $this->requestOrNull($packet, $email);
        if (null === $response) {
            return null;
        }

        $mailnames = $response->xpath('//result/mailname');

        return [] === $mailnames ? null : $this->mailboxInfoFromNode($mailnames[0]);
    }

    public function deleteAddress(string $email): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would delete mail address {email}', ['email' => $email]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $filter = $packet->addChild('mail')->addChild('remove')->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $filter->addChild('name', $name);

        $this->client->request($packet);

        $this->logger->info('Deleted mail address {email}', ['email' => $email]);
    }

    public function setMailboxPassword(string $email, string $password): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would set mailbox password for {email}', ['email' => $email]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        // There is no set-password operation on this API version; the
        // password is a property of the mail account set via update/set.
        // The value goes into child elements (<value>, <type>) - validated
        // against a real server (XML API 1.6.9.1).
        $packet = $this->client->getPacket();
        $set = $packet->addChild('mail')->addChild('update')->addChild('set');
        $filter = $set->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        $passwordNode = $mailname->addChild('password');
        $passwordNode->addChild('value', $password);
        $passwordNode->addChild('type', 'plain');

        $this->client->request($packet);

        $this->logger->info('Set mailbox password for {email}', ['email' => $email]);
    }

    public function setMailboxDescription(string $email, string $description): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would set mailbox description for {email}', ['email' => $email]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $set = $packet->addChild('mail')->addChild('update')->addChild('set');
        $filter = $set->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        $mailname->addChild('description', $description);

        $this->client->request($packet);

        $this->logger->info('Set mailbox description for {email}', ['email' => $email]);
    }

    /**
     * Set several plain-string mailbox properties in ONE update/set packet.
     * Property names must match the server's allowed set list verbatim
     * (e.g. 'description', 'outgoing-messages-mbox-limit').
     *
     * @param array<string, string> $properties element name => value
     */
    public function setMailboxProperties(string $email, array $properties): void
    {
        if ([] === $properties) {
            return;
        }

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would set mailbox properties for {email}: {properties}', [
                'email' => $email,
                'properties' => implode(', ', array_keys($properties)),
            ]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $set = $packet->addChild('mail')->addChild('update')->addChild('set');
        $filter = $set->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        foreach ($properties as $property => $value) {
            $mailname->addChild($property, $value);
        }

        $this->client->request($packet);

        $this->logger->info('Set mailbox properties for {email}: {properties}', [
            'email' => $email,
            'properties' => implode(', ', array_keys($properties)),
        ]);
    }

    /**
     * Set the mailbox size limit in BYTES. Quota is nested, unlike the flat
     * string properties: <mailbox><quota>N</quota></mailbox> - validated live
     * against a real server (XML API 1.6.9.1).
     */
    public function setMailboxQuota(string $email, int $quotaBytes): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would set mailbox quota for {email} to {quota} bytes', [
                'email' => $email,
                'quota' => $quotaBytes,
            ]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $set = $packet->addChild('mail')->addChild('update')->addChild('set');
        $filter = $set->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        $mailbox = $mailname->addChild('mailbox');
        $mailbox->addChild('quota', (string) $quotaBytes);

        $this->client->request($packet);

        $this->logger->info('Set mailbox quota for {email} to {quota} bytes', [
            'email' => $email,
            'quota' => $quotaBytes,
        ]);
    }

    /** @return string[] forwarding recipients currently configured for the mailname */
    public function getForwarding(string $email): array
    {
        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $get = $packet->addChild('mail')->addChild('get_info');
        $filter = $get->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $filter->addChild('name', $name);
        $get->addChild('forwarding');

        $response = $this->requestOrNull($packet, $email);
        if (null === $response) {
            return [];
        }

        $addresses = $response->xpath('//result/mailname/forwarding/address');

        return array_values(array_map(
            static fn ($node): string => (string) $node,
            $addresses,
        ));
    }

    /** @param string[] $addresses */
    public function addForwardingRecipients(string $email, array $addresses): void
    {
        $this->updateForwarding('add', $email, $addresses);
    }

    /** @param string[] $addresses */
    public function removeForwardingRecipients(string $email, array $addresses): void
    {
        $this->updateForwarding('remove', $email, $addresses);
    }

    /** @return string[] alias addresses currently configured for the mailname */
    public function getAliases(string $email): array
    {
        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $get = $packet->addChild('mail')->addChild('get_info');
        $filter = $get->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $filter->addChild('name', $name);
        // The data tag is 'aliases' (plural) - the XSD says only
        // mailbox/forwarding/autoresponder are allowed, but the server
        // accepts 'aliases' (and 'mailbox-usage'); server wins.
        $get->addChild('aliases');

        $response = $this->requestOrNull($packet, $email);
        if (null === $response) {
            return [];
        }

        $mailnames = $response->xpath('//result/mailname');
        if ([] === $mailnames) {
            return [];
        }

        return array_values(array_map(
            static fn ($node): string => (string) $node,
            $mailnames[0]->xpath('alias'),
        ));
    }

    /** @param string[] $aliases */
    public function addAliases(string $email, array $aliases): void
    {
        $this->updateAliases('add', $email, $aliases);
    }

    /** @param string[] $aliases */
    public function removeAliases(string $email, array $aliases): void
    {
        $this->updateAliases('remove', $email, $aliases);
    }

    /**
     * Rename a mail account (local part). The mailbox keeps its settings;
     * only the name changes on the server.
     */
    public function renameAddress(string $email, string $newName): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would rename mail address {email} to {newName}', [
                'email' => $email,
                'newName' => $newName,
            ]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $rename = $packet->addChild('mail')->addChild('rename');
        $rename->addChild('site-id', (string) $siteId);
        $rename->addChild('name', $name);
        $rename->addChild('new-name', $newName);

        $this->client->request($packet);

        $this->logger->info('Renamed mail address {email} to {newName}', [
            'email' => $email,
            'newName' => $newName,
        ]);
    }

    /**
     * Server-wide information: identity, Plesk/OS versions, object counts,
     * resource usage and update status. Packet: <server><get> with the
     * gen_info/stat/updates data tags (schema 1.6.9.1).
     *
     * @return array<string, mixed>
     */
    public function getServerInfo(): array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('server')->addChild('get');
        $get->addChild('gen_info');
        $get->addChild('stat');
        $get->addChild('updates');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $data = $response->xpath('//result')[0] ?? null;
        if (null === $data) {
            throw new \RuntimeException('Server returned no result for server/get.');
        }

        return [
            'server_name' => (string) $data->gen_info->server_name,
            'plesk_version' => (string) $data->stat->version->plesk_version,
            'plesk_os' => (string) $data->stat->version->plesk_os,
            'os_release' => (string) $data->stat->version->os_release,
            'plesk_build' => (string) $data->stat->version->plesk_build,
            'cpu' => (string) $data->stat->other->cpu,
            'uptime' => (string) $data->stat->other->uptime,
            'load_avg' => [
                'l1' => (string) $data->stat->load_avg->l1,
                'l5' => (string) $data->stat->load_avg->l5,
                'l15' => (string) $data->stat->load_avg->l15,
            ],
            'objects' => [
                'clients' => (string) $data->stat->objects->clients,
                'domains' => (string) $data->stat->objects->domains,
                'active_domains' => (string) $data->stat->objects->active_domains,
                'mail_boxes' => (string) $data->stat->objects->mail_boxes,
                'mail_groups' => (string) $data->stat->objects->mail_groups,
                'mail_responders' => (string) $data->stat->objects->mail_responders,
                'web_users' => (string) $data->stat->objects->web_users,
                'databases' => (string) $data->stat->objects->databases,
            ],
            'updates' => [
                'available_update' => (string) $data->updates->available_update,
                'available_update_type' => (string) $data->updates->available_update_type,
                'security_updates' => (string) $data->updates->security_updates,
                'last_installed_update' => (string) $data->updates->last_installed_update,
                'install_automatically' => (string) $data->updates->install_updates_automatically,
            ],
        ];
    }

    /**
     * Currently opened control-panel sessions.
     *
     * @return array<int, array<string, string>>
     */
    public function listSessions(): array
    {
        $packet = $this->client->getPacket();
        $packet->addChild('session')->addChild('get');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $sessions = [];
        foreach ($response->xpath('//result/session') as $session) {
            $sessions[] = [
                'id' => (string) $session->id,
                'type' => (string) $session->type,
                'ip_address' => (string) $session->{'ip-address'},
                'login' => (string) $session->login,
                'login_time' => (string) $session->{'login-time'},
                'idle' => (string) $session->idle,
            ];
        }

        return $sessions;
    }

    /** Close a control-panel session by id. */
    public function terminateSession(string $sessionId): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would terminate session {sessionId}', ['sessionId' => $sessionId]);

            return;
        }

        $packet = $this->client->getPacket();
        $packet->addChild('session')->addChild('terminate')->addChild('session-id', $sessionId);

        $this->client->request($packet);

        $this->logger->info('Terminated session {sessionId}', ['sessionId' => $sessionId]);
    }

    /** Set the webspace status: 0 = enabled, 16 = disabled (validated live). */
    public function setDomainStatus(string $domain, int $status): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would set domain status for {domain} to {status}', [
                'domain' => $domain,
                'status' => $status,
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $set = $packet->addChild('webspace')->addChild('set');
        $set->addChild('filter')->addChild('name', $domain);
        $set->addChild('values')->addChild('gen_setup')->addChild('status', (string) $status);

        $this->client->request($packet);

        $this->logger->info('Set domain status for {domain} to {status}', [
            'domain' => $domain,
            'status' => $status,
        ]);
    }

    /**
     * Plesk Administrator personal information.
     * Packet: <server><get><admin/>.
     *
     * @return array<string, string>
     */
    public function getAdminInfo(): array
    {
        $packet = $this->client->getPacket();
        $packet->addChild('server')->addChild('get')->addChild('admin');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $data = $response->xpath('//result')[0] ?? null;
        if (null === $data || !isset($data->admin)) {
            throw new \RuntimeException('Server returned no admin data for server/get admin.');
        }

        return [
            'cname' => (string) $data->admin->admin_cname,
            'pname' => (string) $data->admin->admin_pname,
            'phone' => (string) $data->admin->admin_phone,
            'fax' => (string) $data->admin->admin_fax,
            'email' => (string) $data->admin->admin_email,
            'address' => (string) $data->admin->admin_address,
            'city' => (string) $data->admin->admin_city,
            'state' => (string) $data->admin->admin_state,
            'pcode' => (string) $data->admin->admin_pcode,
            'country' => (string) $data->admin->admin_country,
            'locale' => (string) $data->admin->admin_locale,
            'multiple_sessions' => (string) $data->admin->admin_multiple_sessions,
        ];
    }

    /**
     * State of the server services (web, mail, dns, ...).
     * Packet: <server><get><services_state/>.
     *
     * @return array<int, array<string, string>>
     */
    public function listServiceStates(): array
    {
        $packet = $this->client->getPacket();
        $packet->addChild('server')->addChild('get')->addChild('services_state');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $services = [];
        foreach ($response->xpath('//result/services_state/srv') as $srv) {
            $services[] = [
                'id' => (string) $srv->id,
                'title' => (string) $srv->title,
                'state' => (string) $srv->state,
                'error' => (string) $srv->error,
            ];
        }

        return $services;
    }

    /** Start, stop or restart a server service. Packet: <server><srv_man>. */
    public function manageService(string $service, string $operation): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would {operation} service {service}', [
                'operation' => $operation,
                'service' => $service,
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $srvMan = $packet->addChild('server')->addChild('srv_man');
        $srvMan->addChild('id', $service);
        $srvMan->addChild('operation', $operation);

        $this->client->request($packet);

        $this->logger->info('{operation} service {service}', [
            'operation' => $operation,
            'service' => $service,
        ]);
    }

    /**
     * IP addresses available on the server.
     * Packet: <ip><get/>.
     *
     * @return array<int, array<string, string>>
     */
    public function listIps(): array
    {
        $packet = $this->client->getPacket();
        $packet->addChild('ip')->addChild('get');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $ips = [];
        foreach ($response->xpath('//result/addresses/ip_info') as $ipInfo) {
            $ips[] = [
                'ip_address' => (string) $ipInfo->ip_address,
                'netmask' => (string) $ipInfo->netmask,
                'type' => (string) $ipInfo->type,
                'interface' => (string) $ipInfo->interface,
                'public_ip_address' => (string) $ipInfo->public_ip_address,
            ];
        }

        return $ips;
    }

    /** Add an IP address to the server (shared or exclusive). */
    public function addIp(string $ipAddress, string $netmask, string $type, string $interface): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would add IP address {ipAddress}', ['ipAddress' => $ipAddress]);

            return;
        }

        $packet = $this->client->getPacket();
        $add = $packet->addChild('ip')->addChild('add');
        $add->addChild('ip_address', $ipAddress);
        $add->addChild('netmask', $netmask);
        $add->addChild('type', $type);
        $add->addChild('interface', $interface);

        $this->client->request($packet);

        $this->logger->info('Added IP address {ipAddress}', ['ipAddress' => $ipAddress]);
    }

    /** Remove an IP address from the server. */
    public function removeIp(string $ipAddress): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would remove IP address {ipAddress}', ['ipAddress' => $ipAddress]);

            return;
        }

        $packet = $this->client->getPacket();
        $del = $packet->addChild('ip')->addChild('del');
        $del->addChild('filter')->addChild('ip_address', $ipAddress);

        $this->client->request($packet);

        $this->logger->info('Removed IP address {ipAddress}', ['ipAddress' => $ipAddress]);
    }

    /** Update IP properties (type, public IP). Packet: <ip><set>. */
    /** @param array<string, string> $properties */
    public function setIp(string $ipAddress, array $properties): void
    {
        if ([] === $properties) {
            return;
        }

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would update IP address {ipAddress}: {properties}', [
                'ipAddress' => $ipAddress,
                'properties' => implode(', ', array_keys($properties)),
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $set = $packet->addChild('ip')->addChild('set');
        $set->addChild('filter')->addChild('ip_address', $ipAddress);
        foreach ($properties as $property => $value) {
            $set->addChild($property, $value);
        }

        $this->client->request($packet);

        $this->logger->info('Updated IP address {ipAddress}: {properties}', [
            'ipAddress' => $ipAddress,
            'properties' => implode(', ', array_keys($properties)),
        ]);
    }

    /**
     * Installed Plesk components.
     * Packet: <server><get><components/>.
     *
     * @return array<int, array<string, string>>
     */
    public function listComponents(): array
    {
        $packet = $this->client->getPacket();
        $packet->addChild('server')->addChild('get')->addChild('components');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $components = [];
        foreach ($response->xpath('//result/components/component') as $component) {
            $components[] = [
                'name' => (string) $component->name,
                'version' => (string) $component->version,
            ];
        }

        return $components;
    }

    /**
     * Install a Plesk component. Packet shape per the official docs
     * (<updater><install-component><update-id>..</update-id><component-id>..
     * </component-id>); NOTE: the 1.6.9.1 schema graph has no updater
     * operator - validate against the live server before relying on it.
     */
    public function installComponent(string $componentId, string $updateId): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would install component {componentId} (update {updateId})', [
                'componentId' => $componentId,
                'updateId' => $updateId,
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $install = $packet->addChild('updater')->addChild('install-component');
        $install->addChild('update-id', $updateId);
        $install->addChild('component-id', $componentId);

        $this->client->request($packet);

        $this->logger->info('Installed component {componentId} (update {updateId})', [
            'componentId' => $componentId,
            'updateId' => $updateId,
        ]);
    }

    /**
     * Create a site (domain). htype is the Plesk hosting notation:
     * vrt_hst = virtual host, std_fwd = forwarding, frm_fwd = frame
     * forwarding, none = no hosting.
     *
     * @param array<string, string> $vrtProperties name => value for virtual hosting
     */
    public function addSite(string $name, string $htype, ?string $webspaceName, ?string $description, array $vrtProperties, ?string $destUrl): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would add site {name} (htype {htype})', [
                'name' => $name,
                'htype' => $htype,
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $add = $packet->addChild('site')->addChild('add');
        $genSetup = $add->addChild('gen_setup');
        $genSetup->addChild('name', $name);
        $genSetup->addChild('htype', $htype);
        if (null !== $webspaceName) {
            $genSetup->addChild('webspace-name', $webspaceName);
        }
        if (null !== $description) {
            $genSetup->addChild('description', $description);
        }

        $hosting = $add->addChild('hosting');
        if ('none' === $htype) {
            $hosting->addChild('none');
        } elseif ('std_fwd' === $htype || 'frm_fwd' === $htype) {
            $node = $hosting->addChild($htype);
            $node->addChild('dest_url', (string) $destUrl);
        } else {
            $vrt = $hosting->addChild('vrt_hst');
            foreach ($vrtProperties as $property => $value) {
                $propertyNode = $vrt->addChild('property');
                $propertyNode->addChild('name', $property);
                $propertyNode->addChild('value', $value);
            }
        }

        $this->client->request($packet);

        $this->logger->info('Added site {name}', ['name' => $name]);
    }

    /** Remove a site (domain) from the server. */
    public function removeSite(string $name): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would remove site {name}', ['name' => $name]);

            return;
        }

        $packet = $this->client->getPacket();
        $packet->addChild('site')->addChild('del')->addChild('filter')->addChild('name', $name);

        $this->client->request($packet);

        $this->logger->info('Removed site {name}', ['name' => $name]);
    }

    /**
     * Traffic usage of a site between two dates (dates as YYYY-MM-DD).
     *
     * @return array<int, array<string, string>>
     */
    public function getSiteTraffic(string $name, ?string $since, ?string $to): array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('site')->addChild('get_traffic');
        $get->addChild('filter')->addChild('name', $name);
        if (null !== $since) {
            $get->addChild('since_date', $since);
        }
        if (null !== $to) {
            $get->addChild('to_date', $to);
        }

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $traffic = [];
        foreach ($response->xpath('//result/traffic') as $row) {
            $traffic[] = [
                'date' => (string) $row->date,
                'http_in' => (string) $row->http_in,
                'http_out' => (string) $row->http_out,
                'ftp_in' => (string) $row->ftp_in,
                'ftp_out' => (string) $row->ftp_out,
                'smtp_in' => (string) $row->smtp_in,
                'smtp_out' => (string) $row->smtp_out,
                'pop3_imap_in' => (string) $row->pop3_imap_in,
                'pop3_imap_out' => (string) $row->pop3_imap_out,
            ];
        }

        return $traffic;
    }

    /**
     * Manually record traffic counters for one site and date (set_traffic
     * addresses the site by id - dom_id).
     *
     * @param array<string, int> $counters smtp_in|smtp_out|pop3_imap_in|pop3_imap_out
     */
    public function setSiteTraffic(string $domain, string $date, array $counters): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would set traffic for {domain} on {date}', [
                'domain' => $domain,
                'date' => $date,
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $set = $packet->addChild('site')->addChild('set_traffic');
        $set->addChild('dom_id', (string) $this->resolveSiteId($domain));
        $set->addChild('date', $date);
        foreach ($counters as $name => $value) {
            $set->addChild($name, (string) $value);
        }

        $this->client->request($packet);

        $this->logger->info('Set traffic for {domain} on {date}: {counters}', [
            'domain' => $domain,
            'date' => $date,
            'counters' => implode(', ', array_keys($counters)),
        ]);
    }

    /**
     * Hosting settings descriptor for a site.
     *
     * @return array<int, array<string, string>>
     */
    public function getHostingDescriptor(string $name): array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('site')->addChild('get-physical-hosting-descriptor');
        $get->addChild('filter')->addChild('name', $name);

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $properties = [];
        foreach ($response->xpath('//result/descriptor/property') as $property) {
            $properties[] = [
                'name' => (string) $property->name,
                'type' => (string) $property->type,
                'default' => (string) $property->{'default-value'},
                'label' => (string) $property->label,
            ];
        }

        return $properties;
    }

    /**
     * Installed extensions.
     * Packet: <extension><get/>.
     *
     * @return array<int, array{id: string, name: string, version: string, release: string, active: bool}>
     */
    public function listExtensions(): array
    {
        $packet = $this->client->getPacket();
        $packet->addChild('extension')->addChild('get');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $extensions = [];
        foreach ($response->xpath('//result/details') as $details) {
            $extensions[] = $this->extensionFromNode($details);
        }

        return $extensions;
    }

    /**
     * One installed extension by id (native get filter), or null.
     *
     * @return null|array{id: string, name: string, version: string, release: string, active: bool}
     */
    public function getExtension(string $id): ?array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('extension')->addChild('get');
        $get->addChild('filter')->addChild('id', $id);

        $response = $this->requestOrNull($packet, $id);
        if (null === $response) {
            return null;
        }

        $details = $response->xpath('//result/details');
        if ([] === $details) {
            return null;
        }

        return $this->extensionFromNode($details[0]);
    }

    /** Install an extension by id or from a URL. */
    public function installExtension(?string $id, ?string $url): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would install extension {id} (url {url})', [
                'id' => $id ?? '-',
                'url' => $url ?? '-',
            ]);

            return;
        }

        $packet = $this->client->getPacket();
        $install = $packet->addChild('extension')->addChild('install');
        if (null !== $id) {
            $install->addChild('id', $id);
        } else {
            $install->addChild('url', (string) $url);
        }

        $this->client->request($packet);

        $this->logger->info('Installed extension {id} (url {url})', [
            'id' => $id ?? '-',
            'url' => $url ?? '-',
        ]);
    }

    /** Uninstall an extension by id. */
    public function uninstallExtension(string $id): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would uninstall extension {id}', ['id' => $id]);

            return;
        }

        $packet = $this->client->getPacket();
        $packet->addChild('extension')->addChild('uninstall')->addChild('id', $id);

        $this->client->request($packet);

        $this->logger->info('Uninstalled extension {id}', ['id' => $id]);
    }

    /**
     * Call an extension operation. Shape (docs "Calling Extensions
     * Operations", validated live): the extension id is the element NAME
     * under <call>, the operation is its child, the operation's parameters
     * its grandchildren:
     * <extension><call><git><remove><domain>..</domain><name>..</name>
     * </remove></git></call></extension>. Operation names are defined by
     * each extension (e.g. the Git Manager extension's ops, not 'info').
     *
     * @param array<string, string> $params operation parameters (element name => value)
     */
    public function callExtension(string $id, string $operation, array $params = []): void
    {
        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would call extension {id} operation {operation}', [
                'id' => $id,
                'operation' => $operation,
            ]);

            return;
        }

        foreach ([$id, $operation, ...array_keys($params)] as $name) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', (string) $name)) {
                throw new \InvalidArgumentException(sprintf('Invalid XML element name in extension call: "%s"', $name));
            }
        }

        $packet = $this->client->getPacket();
        $call = $packet->addChild('extension')->addChild('call');
        $operationNode = $call->addChild($id)->addChild($operation);
        foreach ($params as $name => $value) {
            $operationNode->addChild($name, (string) $value);
        }

        $this->client->request($packet);

        $this->logger->info('Called extension {id} operation {operation}', [
            'id' => $id,
            'operation' => $operation,
        ]);
    }

    /** @return array{enabled: bool, subject: string, text: string, content_type: string, charset: string, end_date: null|string} */
    private function autoresponderFromNode(\SimpleXMLElement $autoresponder): array
    {
        return [
            'enabled' => 'true' === strtolower((string) $autoresponder->enabled),
            'subject' => (string) $autoresponder->subject,
            'text' => (string) $autoresponder->text,
            'content_type' => (string) $autoresponder->content_type,
            'charset' => (string) $autoresponder->charset,
            'end_date' => '' === (string) $autoresponder->end_date ? null : (string) $autoresponder->end_date,
        ];
    }

    /** @return array<int, array<string, int|string>> */
    private function listWebspaces(): array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('webspace')->addChild('get');
        // <filter> first (empty = all webspaces), then a dataset requesting
        // gen_info (an empty dataset makes the server omit the ids).
        $get->addChild('filter');
        $get->addChild('dataset')->addChild('gen_info');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $domains = [];
        foreach ($response->xpath('//result') as $node) {
            $id = (int) $node->id;
            if (0 === $id && isset($node->data->id)) {
                $id = (int) $node->data->id;
            }

            $name = '';
            if (isset($node->data->gen_info->name)) {
                $name = (string) $node->data->gen_info->name;
            }
            if ('' === $name) {
                $name = (string) $node->name;
            }

            if (0 === $id) {
                // Never emit a bogus id of 0 (Plesk rejects it in follow-up
                // requests); skip results without an id.
                continue;
            }

            $domains[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $domains;
    }

    /** @return array<int, array<string, int|string>> */
    private function listSites(): array
    {
        $packet = $this->client->getPacket();
        $get = $packet->addChild('site')->addChild('get');
        $get->addChild('filter');
        $get->addChild('dataset')->addChild('gen_info');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);

        $domains = [];
        foreach ($response->xpath('//result') as $node) {
            $id = (int) $node->id;
            if (0 === $id && isset($node->data->id)) {
                $id = (int) $node->data->id;
            }

            $name = (string) $node->name;
            if ('' === $name && isset($node->data->gen_info->name)) {
                $name = (string) $node->data->gen_info->name;
            }

            if (0 === $id) {
                continue;
            }

            $domains[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $domains;
    }

    /**
     * Run one mail get_info per email inside a single batched packet.
     *
     * @param string[]                           $emails
     * @param string|string[]                    $dataTags requested get_info data tags
     * @param callable(\SimpleXMLElement): mixed $parse    mailname -> value
     *
     * @return array<string, mixed>
     */
    private function bulkRead(array $emails, array|string $dataTags, callable $parse): array
    {
        if ([] === $emails) {
            return [];
        }

        $requests = [];
        foreach ($emails as $email) {
            [$siteId, $name] = $this->resolveEmail($email);
            $request = ['mail' => ['get_info' => ['filter' => ['site-id' => (string) $siteId, 'name' => $name]]]];
            foreach ((array) $dataTags as $tag) {
                $request['mail']['get_info'][$tag] = '';
            }
            $requests[] = $request;
        }

        $result = [];
        foreach ($this->batchQueries($requests) as $index => $response) {
            $email = $emails[$index];
            $mailnames = [];
            foreach ($this->okResults($response, sprintf('mail get_info for %s', $email)) as $okResult) {
                foreach ($okResult->mailname as $mailname) {
                    $mailnames[] = $mailname;
                }
            }

            // A mailname that does not exist yields an error result (skipped
            // above), so an empty list means "not found" -> null.
            $result[$email] = [] === $mailnames ? null : $parse($mailnames[0]);
        }

        return $result;
    }

    /**
     * Send many requests in one HTTP POST (Plesk multi-operation packet).
     * Unlike request(), per-operation errors do not throw: each result is
     * inspected individually via okResults().
     *
     * @param list<array<string, mixed>> $requests array-form API requests
     *
     * @return list<\SimpleXMLElement> one response per request, in request order
     */
    private function batchQueries(array $requests): array
    {
        return $this->client->multiRequest($requests, Client::RESPONSE_FULL);
    }

    /**
     * @return list<\SimpleXMLElement> ok results only; error results are
     *                                 logged with the context and skipped
     */
    private function okResults(\SimpleXMLElement $response, string $context): array
    {
        $results = [];
        foreach ($response->xpath('//result') as $result) {
            if ('error' === strtolower((string) $result->status)) {
                $this->logger->warning('{context}: {errtext}', [
                    'context' => $context,
                    'errtext' => (string) $result->errtext,
                ]);

                continue;
            }

            $results[] = $result;
        }

        return $results;
    }

    /** @return array{name: string, description: string, mailbox_enabled: bool, mailbox_quota: int, mailbox_usage: int, forwarding: string[], autoresponder_enabled: bool, antivir: string, outgoing_messages_mbox_limit: string} */
    private function mailboxInfoFromNode(\SimpleXMLElement $mailname): array
    {
        return [
            'name' => (string) $mailname->name,
            'description' => (string) $mailname->description,
            'mailbox_enabled' => 'true' === strtolower((string) $mailname->mailbox->enabled),
            // The <usage> element only appears when the 'mailbox-usage' data
            // tag was requested; quota is always part of <mailbox>.
            'mailbox_quota' => (int) $mailname->mailbox->quota,
            'mailbox_usage' => (int) $mailname->mailbox->usage,
            'forwarding' => array_values(array_map(
                static fn ($node): string => (string) $node,
                $mailname->xpath('forwarding/address'),
            )),
            'autoresponder_enabled' => 'true' === strtolower((string) $mailname->autoresponder->enabled),
            'antivir' => (string) $mailname->antivir,
            'outgoing_messages_mbox_limit' => (string) $mailname->{'outgoing-messages-mbox-limit'},
        ];
    }

    /**
     * Perform a read request, returning null when Plesk reports the mailname
     * does not exist (the read methods otherwise have no "not found" signal -
     * the lib's verifyResponse turns it into an exception). Genuine errors
     * (auth, network, ...) still propagate.
     */
    private function requestOrNull(\SimpleXMLElement $packet, string $email): ?XmlResponse
    {
        try {
            return $this->client->request($packet, Client::RESPONSE_FULL);
        } catch (Exception $e) {
            if (preg_match('/does not exist|not found/i', $e->getMessage())) {
                $this->logger->debug('Mail address {email} not found on the server', ['email' => $email]);

                return null;
            }

            throw $e;
        }
    }

    /** @return array{0: int, 1: string} [site-id, mailname] */
    private function resolveEmail(string $email): array
    {
        if (!str_contains($email, '@')) {
            throw new \InvalidArgumentException(sprintf('Not a valid email address: "%s"', $email));
        }

        [$name, $domain] = explode('@', $email, 2);

        return [$this->resolveSiteId($domain), $name];
    }

    /** @param string[] $addresses */
    private function updateForwarding(string $operation, string $email, array $addresses): void
    {
        if ([] === $addresses) {
            return;
        }

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would call mail update {operation} forwarding for {email}: {addresses}', [
                'operation' => $operation,
                'email' => $email,
                'addresses' => implode(', ', $addresses),
            ]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $update = $packet->addChild('mail')->addChild('update');
        $subOperation = $update->addChild($operation);
        $filter = $subOperation->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        $forwarding = $mailname->addChild('forwarding');
        foreach ($addresses as $address) {
            $forwarding->addChild('address', $address);
        }

        $this->client->request($packet);

        $this->logger->info('Updated forwarding for {email}: {operation} {addresses}', [
            'operation' => $operation,
            'email' => $email,
            'addresses' => implode(', ', $addresses),
        ]);
    }

    /**
     * Alias entries are plain string elements (XSD: alias type string, 0..∞);
     * add/remove keep the other settings untouched.
     *
     * @param string[] $aliases
     */
    private function updateAliases(string $operation, string $email, array $aliases): void
    {
        if ([] === $aliases) {
            return;
        }

        if ($this->dryRun) {
            $this->logger->info('DRY-RUN: would call mail update {operation} aliases for {email}: {aliases}', [
                'operation' => $operation,
                'email' => $email,
                'aliases' => implode(', ', $aliases),
            ]);

            return;
        }

        [$siteId, $name] = $this->resolveEmail($email);

        $packet = $this->client->getPacket();
        $update = $packet->addChild('mail')->addChild('update');
        $subOperation = $update->addChild($operation);
        $filter = $subOperation->addChild('filter');
        $filter->addChild('site-id', (string) $siteId);
        $mailname = $filter->addChild('mailname');
        $mailname->addChild('name', $name);
        foreach ($aliases as $alias) {
            $mailname->addChild('alias', $alias);
        }

        $this->client->request($packet);

        $this->logger->info('Updated aliases for {email}: {operation} {aliases}', [
            'operation' => $operation,
            'email' => $email,
            'aliases' => implode(', ', $aliases),
        ]);
    }

    private function resolveSiteId(string $domain): int
    {
        if (isset($this->siteIdCache[$domain])) {
            return $this->siteIdCache[$domain];
        }

        $packet = $this->client->getPacket();
        $get = $packet->addChild('site')->addChild('get');
        $get->addChild('filter')->addChild('name', $domain);
        $get->addChild('dataset')->addChild('gen_info');

        $response = $this->client->request($packet, Client::RESPONSE_FULL);
        $ids = $response->xpath('//result/id');
        if ([] === $ids) {
            throw new \RuntimeException(sprintf('Unable to resolve domain "%s" to a Plesk site id.', $domain));
        }

        return $this->siteIdCache[$domain] = (int) $ids[0];
    }

    private function toPleskDate(string $iso8601): string
    {
        return new \DateTimeImmutable($iso8601)->format('Y-m-d');
    }

    /** @return array{id: string, name: string, version: string, release: string, active: bool} */
    private function extensionFromNode(\SimpleXMLElement $details): array
    {
        return [
            'id' => (string) $details->id,
            'name' => (string) $details->name,
            'version' => (string) $details->version,
            'release' => (string) $details->release,
            'active' => 'true' === strtolower((string) $details->active),
        ];
    }
}
