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

        $response = $this->client->request($packet, Client::RESPONSE_FULL);
        $autoresponders = $response->xpath('//result/mailname/autoresponder');
        if ([] === $autoresponders) {
            return null;
        }

        $autoresponder = $autoresponders[0];

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

        $response = $this->client->request($packet, Client::RESPONSE_FULL);
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
        $get->addChild('dataset');

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
