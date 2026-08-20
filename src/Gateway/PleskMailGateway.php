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
    ) {
    }

    /**
     * @return array{
     *     enabled: bool,
     *     subject: string,
     *     text: string,
     *     content_type: string,
     *     charset: string,
     *     end_date: string|null,
     * }|null
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

    /** @return array{enabled: bool, subject: string, text: string, content_type: string, charset: string, end_date: string|null} */
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
     * @return array<string, mixed>|null null when the domain does not exist
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
            if (isset($data->$section)) {
                foreach ($data->$section->children() as $key => $value) {
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

    /** @param string[] $emails @return array<string, string[]> */
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

    /** @param string[] $emails @return array<string, array<string, mixed>|null> */
    public function getAutoresponderBulk(array $emails): array
    {
        return $this->bulkRead($emails, 'autoresponder', function (\SimpleXMLElement $mailname): ?array {
            $autoresponders = $mailname->xpath('autoresponder');

            return [] === $autoresponders ? null : $this->autoresponderFromNode($autoresponders[0]);
        });
    }

    /** @param string[] $emails @return array<string, array<string, mixed>|null> */
    public function getMailboxInfoBulk(array $emails): array
    {
        return $this->bulkRead($emails, ['mailbox', 'forwarding', 'autoresponder'], function (\SimpleXMLElement $mailname): ?array {
            return $this->mailboxInfoFromNode($mailname);
        });
    }

    /**
     * Run one mail get_info per email inside a single batched packet.
     *
     * @param string[]                   $emails
     * @param string|string[]            $dataTags requested get_info data tags
     * @param callable(\SimpleXMLElement): mixed $parse  mailname -> value
     *
     * @return array<string, mixed>
     */
    private function bulkRead(array $emails, string|array $dataTags, callable $parse): array
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
     *                                  logged with the context and skipped
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

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     mailbox_enabled: bool,
     *     forwarding: string[],
     *     autoresponder_enabled: bool,
     * }|null
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
        $get->addChild('forwarding');
        $get->addChild('autoresponder');

        $response = $this->requestOrNull($packet, $email);
        if (null === $response) {
            return null;
        }

        $mailnames = $response->xpath('//result/mailname');

        return [] === $mailnames ? null : $this->mailboxInfoFromNode($mailnames[0]);
    }

    /** @return array{name: string, description: string, mailbox_enabled: bool, forwarding: string[], autoresponder_enabled: bool} */
    private function mailboxInfoFromNode(\SimpleXMLElement $mailname): array
    {
        return [
            'name' => (string) $mailname->name,
            'description' => (string) $mailname->description,
            'mailbox_enabled' => 'true' === strtolower((string) $mailname->mailbox->enabled),
            'forwarding' => array_values(array_map(
                static fn ($node): string => (string) $node,
                $mailname->xpath('forwarding/address'),
            )),
            'autoresponder_enabled' => 'true' === strtolower((string) $mailname->autoresponder->enabled),
        ];
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
     * Perform a read request, returning null when Plesk reports the mailname
     * does not exist (the read methods otherwise have no "not found" signal -
     * the lib's verifyResponse turns it into an exception). Genuine errors
     * (auth, network, ...) still propagate.
     */
    private function requestOrNull(\SimpleXMLElement $packet, string $email): ?XmlResponse
    {
        try {
            return $this->client->request($packet, Client::RESPONSE_FULL);
        } catch (\PleskX\Api\Exception $e) {
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
        return (new \DateTimeImmutable($iso8601))->format('Y-m-d');
    }
}
