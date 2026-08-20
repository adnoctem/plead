<?php

declare(strict_types=1);

namespace App\Tests\Gateway;

use App\Gateway\PleskMailGateway;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use PleskX\Api\Client;
use PleskX\Api\XmlResponse;

final class PleskMailGatewayTest extends TestCase
{
    private FakeClient $client;
    private PleskMailGateway $gateway;

    protected function setUp(): void
    {
        $this->client = new FakeClient();
        $this->gateway = new PleskMailGateway($this->client, false, new Logger('test'));
    }

    public function testGetAutoresponderBuildsPacketWithSiteIdAndName(): void
    {
        $this->client->autoresponderXml = <<<'XML'
        <packet>
          <mail>
            <get_info>
              <result>
                <status>ok</status>
                <mailname>
                  <id>42</id>
                  <name>user</name>
                  <autoresponder>
                    <enabled>true</enabled>
                    <subject>Regarding controllers</subject>
                    <text>Gerne &amp; sofort</text>
                    <content_type>text/plain</content_type>
                    <charset>utf-8</charset>
                    <end_date>2026-08-20</end_date>
                  </autoresponder>
                </mailname>
              </result>
            </get_info>
          </mail>
        </packet>
        XML;

        $result = $this->gateway->getAutoresponder('user@company.com');

        self::assertSame([
            'enabled' => true,
            'subject' => 'Regarding controllers',
            'text' => 'Gerne & sofort',
            'content_type' => 'text/plain',
            'charset' => 'utf-8',
            'end_date' => '2026-08-20',
        ], $result);

        $sitePacket = $this->client->requests[0];
        self::assertStringContainsString('<site><get>', $sitePacket);
        self::assertStringContainsString('<name>company.com</name>', $sitePacket);
        self::assertStringContainsString('<dataset><gen_info/>', $sitePacket);

        $getPacket = $this->client->requests[1];
        self::assertStringContainsString('<mail><get_info>', $getPacket);
        self::assertStringContainsString('<site-id>17</site-id>', $getPacket);
        self::assertStringContainsString('<name>user</name>', $getPacket);
        self::assertStringContainsString('<autoresponder/>', $getPacket);
    }

    public function testGetAutoresponderReturnsNullWhenAbsent(): void
    {
        $this->client->autoresponderXml = <<<'XML'
        <packet>
          <mail>
            <get_info>
              <result>
                <status>ok</status>
                <mailname>
                  <id>42</id>
                  <name>user</name>
                </mailname>
              </result>
            </get_info>
          </mail>
        </packet>
        XML;

        self::assertNull($this->gateway->getAutoresponder('user@company.com'));
    }

    public function testSiteIdIsCachedAcrossCalls(): void
    {
        $this->gateway->getAutoresponder('a@company.com');
        $this->gateway->getAutoresponder('b@company.com');

        $sitePackets = array_values(array_filter(
            $this->client->requests,
            static fn (string $request): bool => str_contains($request, '<site><get>'),
        ));

        self::assertCount(1, $sitePackets);
    }

    public function testSetAutoresponderBuildsPacketWithDatesConverted(): void
    {
        $this->gateway->setAutoresponder('user@company.com', "Hallo!\nBis bald.", '2026-08-20T09:00:00+02:00');

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<site-id>17</site-id>', $packet);
        self::assertStringContainsString('<mailname><name>user</name>', $packet);
        self::assertStringContainsString('<enabled>true</enabled>', $packet);
        self::assertStringContainsString("<text>Hallo!\nBis bald.</text>", $packet);
        self::assertStringContainsString('<end_date>2026-08-20</end_date>', $packet);
        self::assertStringNotContainsString('content_type', $packet);
        self::assertStringNotContainsString('charset', $packet);
    }

    public function testDisableAutoresponderBuildsPacket(): void
    {
        $this->gateway->disableAutoresponder('user@company.com');

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<autoresponder><enabled>false</enabled>', $packet);
    }

    public function testDryRunSkipsRequest(): void
    {
        $gateway = new PleskMailGateway($this->client, true, new Logger('test'));

        $gateway->setAutoresponder('user@company.com', 'msg', '2026-08-20T09:00:00+02:00');

        $mutating = array_values(array_filter(
            $this->client->requests,
            static fn (string $request): bool => str_contains($request, '<mail><update>'),
        ));

        self::assertSame([], $mutating);
    }

    public function testUnresolvableDomainThrows(): void
    {
        $this->client->siteNotFound = true;

        $this->expectException(\RuntimeException::class);

        $this->gateway->getAutoresponder('user@missing.example');
    }

    public function testInvalidEmailThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway->getAutoresponder('no-at-sign');
    }

    public function testGetForwardingParsesAddresses(): void
    {
        $this->client->forwardingXml = <<<'XML'
        <packet>
          <mail>
            <get_info>
              <result>
                <status>ok</status>
                <mailname>
                  <id>42</id>
                  <name>group</name>
                  <forwarding>
                    <address>alice@company.com</address>
                    <address>bob@company.com</address>
                  </forwarding>
                </mailname>
              </result>
            </get_info>
          </mail>
        </packet>
        XML;

        $recipients = $this->gateway->getForwarding('group@company.com');

        self::assertSame(['alice@company.com', 'bob@company.com'], $recipients);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><get_info>', $packet);
        self::assertStringContainsString('<name>group</name>', $packet);
        self::assertStringContainsString('<forwarding/>', $packet);
    }

    public function testGetForwardingReturnsEmptyWhenAbsent(): void
    {
        $this->client->forwardingXml = <<<'XML'
        <packet>
          <mail>
            <get_info>
              <result>
                <status>ok</status>
                <mailname>
                  <id>42</id>
                  <name>group</name>
                </mailname>
              </result>
            </get_info>
          </mail>
        </packet>
        XML;

        self::assertSame([], $this->gateway->getForwarding('group@company.com'));
    }

    public function testNotFoundMailnameMapsToNull(): void
    {
        $this->client->mailDoesNotExist = true;

        self::assertNull($this->gateway->getAutoresponder('ghost@company.com'));
        self::assertNull($this->gateway->getMailboxInfo('ghost@company.com'));
        self::assertSame([], $this->gateway->getForwarding('ghost@company.com'));
    }

    public function testAddForwardingRecipientsBuildsUpdateAddPacket(): void
    {
        $this->gateway->addForwardingRecipients('group@company.com', ['alice@company.com', 'bob@company.com']);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><add>', $packet);
        self::assertStringContainsString('<site-id>17</site-id>', $packet);
        self::assertStringContainsString('<mailname><name>group</name>', $packet);
        self::assertStringContainsString('<forwarding>', $packet);
        self::assertStringContainsString('<address>alice@company.com</address>', $packet);
        self::assertStringContainsString('<address>bob@company.com</address>', $packet);
    }

    public function testRemoveForwardingRecipientsBuildsUpdateRemovePacket(): void
    {
        $this->gateway->removeForwardingRecipients('group@company.com', ['bob@company.com']);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><remove>', $packet);
        self::assertStringContainsString('<address>bob@company.com</address>', $packet);
    }

    public function testForwardingUpdateSkipsEmptyAddressList(): void
    {
        $this->gateway->addForwardingRecipients('group@company.com', []);

        self::assertCount(0, $this->client->requests);
    }

    public function testSetMailboxPasswordBuildsUpdateSetPacket(): void
    {
        $this->gateway->setMailboxPassword('user@company.com', 'secret123');

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<mailname><name>user</name>', $packet);
        self::assertStringContainsString('<password><value>secret123</value><type>plain</type>', $packet);
        self::assertStringNotContainsString('<set-password>', $packet);
    }

    public function testSetMailboxDescriptionBuildsPacket(): void
    {
        $this->gateway->setMailboxDescription('user@company.com', 'Holiday replacement');

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<description>Holiday replacement</description>', $packet);
    }

    public function testListDomainsEnumeratesWebspaces(): void
    {
        $result = $this->gateway->listDomains();

        $packet = $this->client->requests[0];
        // The Plesk schema requires <filter> as the first child of <get>, and
        // the dataset must request gen_info.
        self::assertStringContainsString('<webspace><get><filter/><dataset><gen_info/>', $packet);
        self::assertLessThan(
            strpos($packet, '<dataset'),
            (int) strpos($packet, '<filter'),
            'filter must precede dataset in the webspace-get packet',
        );

        self::assertSame([['id' => 1, 'name' => 'company.com']], $result);
    }

    public function testListDomainsFallsBackToSitesWhenWebspacesEmpty(): void
    {
        $this->client->webspaceXml = '<packet><webspace><get><result><status>ok</status></result></get></webspace></packet>';

        $result = $this->gateway->listDomains();

        self::assertSame([['id' => 1, 'name' => 'company.com']], $result);
        self::assertStringContainsString('<site><get>', $this->client->requests[1]);
    }

    public function testGetDomainBuildsFilterAndDatasetsAndParsesGenInfo(): void
    {
        $result = $this->gateway->getDomain('company.com');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<webspace><get>', $packet);
        self::assertStringContainsString('<filter><name>company.com</name></filter>', $packet);
        self::assertStringContainsString('<dataset><gen_info/><hosting/><limits/><prefs/>', $packet);

        self::assertNotNull($result);
        self::assertSame(17, $result['id']);
        self::assertSame('company.com', $result['name']);
        self::assertSame('0', $result['status']);
        self::assertSame('std_fwd', $result['htype']);
        self::assertSame('admin', $result['owner_login']);
        self::assertSame(['87.106.59.215', '2a01:239::1'], $result['ip_addresses']);
        self::assertSame('Main domain', $result['description']);
        self::assertSame('company', $result['hosting']['ftp_login']);
        self::assertSame(['max_dom' => '10'], $result['limits']);
    }

    public function testGetDomainReturnsNullWhenMissing(): void
    {
        $this->client->webspaceInfoXml = '<packet><webspace><get><result><status>error</status><errcode>1013</errcode><errtext>Domain not found</errtext></result></get></webspace></packet>';

        self::assertNull($this->gateway->getDomain('missing.example'));
    }

    public function testUpdateDomainBuildsSetPacket(): void
    {
        $this->gateway->updateDomain('company.com', 'New description');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<webspace><set>', $packet);
        self::assertStringContainsString('<filter><name>company.com</name></filter>', $packet);
        self::assertStringContainsString('<values><gen_setup><description>New description</description>', $packet);
    }

    public function testListDomainsReadsIdFromDataLevelAsFallback(): void
    {
        $this->client->webspaceXml = '<packet><webspace><get><result><status>ok</status><data><id>7</id><gen_info><name>other.com</name></gen_info></data></result></get></webspace></packet>';

        $result = $this->gateway->listDomains();

        self::assertSame([['id' => 7, 'name' => 'other.com']], $result);
    }

    public function testListDomainsSkipsResultsWithoutId(): void
    {
        $this->client->webspaceXml = '<packet><webspace><get><result><status>ok</status><data><gen_info><name>orphan.com</name></gen_info></data></result></get></webspace></packet>';
        $this->client->siteListXml = '<packet><site><get><result><status>ok</status><data><gen_info><name>orphan.com</name></gen_info></data></result></get></site></packet>';

        $result = $this->gateway->listDomains();

        self::assertSame([], $result);
    }

    public function testListMailnamesReturnsRows(): void
    {
        $result = $this->gateway->listMailnames(17);

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<mail><get_info>', $packet);
        self::assertStringContainsString('<site-id>17</site-id>', $packet);

        self::assertSame([
            ['id' => 42, 'name' => 'user', 'description' => 'The user'],
            ['id' => 43, 'name' => 'group', 'description' => ''],
        ], $result);
    }

    public function testListMailnamesBulkBuildsOneBatchAndParsesRows(): void
    {
        $this->client->bulkResponses = [
            '<packet><mail><get_info><result><status>ok</status><mailname><id>1</id><name>admin</name><description/></mailname></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>ok</status><mailname><id>2</id><name>postmaster</name><description/></mailname></result></get_info></mail></packet>',
        ];

        $rows = $this->gateway->listMailnamesBulk([15, 16]);

        self::assertCount(1, $this->client->multiRequests, 'exactly one batched packet');
        self::assertSame([
            ['mail' => ['get_info' => ['filter' => ['site-id' => '15']]]],
            ['mail' => ['get_info' => ['filter' => ['site-id' => '16']]]],
        ], $this->client->multiRequests[0]);

        self::assertSame([
            ['site_id' => 15, 'id' => 1, 'name' => 'admin', 'description' => ''],
            ['site_id' => 16, 'id' => 2, 'name' => 'postmaster', 'description' => ''],
        ], $rows);
    }

    public function testListMailnamesBulkSkipsEmptyAndErrorResults(): void
    {
        $this->client->bulkResponses = [
            '<packet><mail><get_info><result><status>ok</status><mailname><id>1</id><name>admin</name><description/></mailname></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>ok</status></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>error</status><errcode>1013</errcode><errtext>mail does not exist</errtext></result></get_info></mail></packet>',
        ];

        $rows = $this->gateway->listMailnamesBulk([15, 16, 17]);

        self::assertSame([
            ['site_id' => 15, 'id' => 1, 'name' => 'admin', 'description' => ''],
        ], $rows);
    }

    public function testBulkReadsParseAutorespondersAndForwarding(): void
    {
        $this->client->bulkResponses = [
            '<packet><mail><get_info><result><status>ok</status><mailname><id>1</id><name>admin</name><autoresponder><enabled>true</enabled><subject>Re: &lt;request_subject&gt;</subject><text>On vacation</text><content_type>text/plain</content_type><charset>UTF-8</charset><end_date>2026-12-31</end_date></autoresponder></mailname></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>error</status><errcode>1013</errcode><errtext>mail does not exist</errtext></result></get_info></mail></packet>',
        ];
        $autoresponders = $this->gateway->getAutoresponderBulk(['admin@company.com', 'nobody@company.com']);

        $this->client->bulkResponses = [
            '<packet><mail><get_info><result><status>ok</status><mailname><id>2</id><name>group</name><forwarding><address>alice@company.com</address></forwarding></mailname></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>error</status><errcode>1013</errcode><errtext>mail does not exist</errtext></result></get_info></mail></packet>',
        ];
        $forwarding = $this->gateway->getForwardingBulk(['group@company.com', 'other@company.com']);

        // Second op of each bulk is an error result -> null/empty.
        self::assertNotNull($autoresponders['admin@company.com']);
        self::assertTrue($autoresponders['admin@company.com']['enabled']);
        self::assertSame('On vacation', $autoresponders['admin@company.com']['text']);
        self::assertNull($autoresponders['nobody@company.com']);

        self::assertSame(['alice@company.com'], $forwarding['group@company.com']);
        self::assertSame([], $forwarding['other@company.com']);

        self::assertCount(2, $this->client->multiRequests, 'one batch per bulk call');
    }

    public function testBulkMailboxInfoParsesAndMapsMissingToNull(): void
    {
        $this->client->bulkResponses = [
            '<packet><mail><get_info><result><status>ok</status><mailname><id>1</id><name>admin</name><mailbox><enabled>true</enabled><quota>268435456</quota></mailbox><forwarding><enabled>false</enabled></forwarding><autoresponder><enabled>false</enabled></autoresponder></mailname></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>error</status><errcode>1013</errcode><errtext>mail does not exist</errtext></result></get_info></mail></packet>',
        ];

        $infos = $this->gateway->getMailboxInfoBulk(['admin@company.com', 'ghost@company.com']);

        self::assertNotNull($infos['admin@company.com']);
        self::assertTrue($infos['admin@company.com']['mailbox_enabled']);
        self::assertFalse($infos['admin@company.com']['autoresponder_enabled']);
        self::assertSame([], $infos['admin@company.com']['forwarding']);
        self::assertNull($infos['ghost@company.com']);
    }
}

final class FakeClient extends Client
{
    /** @var string[] single requests recorded as XML */
    public array $requests = [];

    /** @var list<array<string, mixed>> batched request arrays recorded by multiRequest */
    public array $multiRequests = [];

    public string $autoresponderXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>user</name></mailname></result></get_info></mail></packet>';

    public string $forwardingXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>group</name></mailname></result></get_info></mail></packet>';

    public string $webspaceXml = '<packet><webspace><get><result><status>ok</status><id>1</id><data><gen_info><name>company.com</name></gen_info></data></result></get></webspace></packet>';

    public string $webspaceInfoXml = '<packet><webspace><get><result><status>ok</status><id>17</id><data><gen_info><cr_date>2024-09-06</cr_date><name>company.com</name><ascii-name>company.com</ascii-name><status>0</status><real_size>98258944</real_size><owner-login>admin</owner-login><dns_ip_address>87.106.59.215</dns_ip_address><dns_ip_address>2a01:239::1</dns_ip_address><htype>std_fwd</htype><guid>abc-123</guid><external-id/><description>Main domain</description><admin-description/></gen_info><hosting><vrt_hst><ftp-login>company</ftp-login><www-root>/var/www/company</www-root><ip-address>87.106.59.215</ip-address></vrt_hst></hosting><limits><max_dom>10</max_dom></limits></data></result></get></webspace></packet>';

    public string $siteListXml = '<packet><site><get><result><status>ok</status><id>1</id><data><gen_info><name>company.com</name></gen_info></data></result></get></site></packet>';

    public string $mailnameListXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>user</name><description>The user</description></mailname></result><result><status>ok</status><mailname><id>43</id><name>group</name></mailname></result></get_info></mail></packet>';

    /** @var list<string> per-operation responses returned by multiRequest */
    public array $bulkResponses = [];

    public bool $siteNotFound = false;

    public bool $mailDoesNotExist = false;

    public function __construct()
    {
        parent::__construct('fake.local', 8443, 'https');
    }

    public function request($request, int $mode = self::RESPONSE_SHORT): XmlResponse
    {
        $xml = $request instanceof \SimpleXMLElement ? $request->asXML() : (string) $request;
        $this->requests[] = (string) $xml;

        if ($this->siteNotFound) {
            return new XmlResponse('<packet><site><get><result><status>error</status><errcode>1013</errcode><errtext>Domain not found</errtext></result></get></site></packet>');
        }

        if (str_contains($xml, '<webspace><get>')) {
            if (str_contains($xml, '<filter/>')) {
                // Empty filter = enumerate all webspaces.
                return new XmlResponse($this->webspaceXml);
            }
            if (str_contains($xml, '<name>')) {
                // Filtered by name = getDomain (full gen_info response).
                return new XmlResponse($this->webspaceInfoXml);
            }

            return new XmlResponse('<packet><webspace><get><result><status>ok</status><id>17</id></result></get></webspace></packet>');
        }

        if (str_contains($xml, '<webspace><set>')) {
            return new XmlResponse('<packet><webspace><set><result><status>ok</status></result></set></webspace></packet>');
        }

        if (str_contains($xml, '<site><get>')) {
            // Empty filter = enumerate all sites (site fallback).
            return str_contains($xml, '<filter/>')
                ? new XmlResponse($this->siteListXml)
                : new XmlResponse('<packet><site><get><result><status>ok</status><id>17</id></result></get></site></packet>');
        }

        if ($this->mailDoesNotExist) {
            // Only mail requests (not site lookups) fail this way.
            throw new \PleskX\Api\Exception('mail does not exist', 1013);
        }

        if (str_contains($xml, '<forwarding/>') || str_contains($xml, '<forwarding>')) {
            return new XmlResponse($this->forwardingXml);
        }

        if (str_contains($xml, '<autoresponder/>')) {
            return new XmlResponse($this->autoresponderXml);
        }

        if (str_contains($xml, '<mail><update>') || str_contains($xml, '<mail><remove>')) {
            return new XmlResponse('<packet><mail><update><set><result><status>ok</status><mailname><name>x</name></mailname></result></set></update></mail></packet>');
        }

        if (str_contains($xml, '<site-id>')) {
            // get_info without forwarding/autoresponder data = listMailnames.
            return new XmlResponse($this->mailnameListXml);
        }

        return new XmlResponse($this->autoresponderXml);
    }

    public function multiRequest(array $requests, int $mode = self::RESPONSE_SHORT): array
    {
        $this->multiRequests[] = $requests;

        $responses = [];
        foreach ($requests as $index => $request) {
            // The real lib parses each sub-response back via
            // simplexml_load_string, yielding plain SimpleXMLElement.
            $responses[] = simplexml_load_string(
                $this->bulkResponses[$index] ?? '<packet><mail><get_info><result><status>ok</status></result></get_info></mail></packet>',
            );
        }

        return $responses;
    }
}
