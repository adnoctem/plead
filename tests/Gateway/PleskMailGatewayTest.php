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
        self::assertStringContainsString('<dataset/>', $sitePacket);

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
}

final class FakeClient extends Client
{
    /** @var string[] */
    public array $requests = [];

    public string $autoresponderXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>user</name></mailname></result></get_info></mail></packet>';

    public string $forwardingXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>group</name></mailname></result></get_info></mail></packet>';

    public bool $siteNotFound = false;

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

        if (str_contains($xml, '<site><get>')) {
            return new XmlResponse('<packet><site><get><result><status>ok</status><id>17</id></result></get></site></packet>');
        }

        if (str_contains($xml, '<forwarding/>') || str_contains($xml, '<forwarding>')) {
            return new XmlResponse($this->forwardingXml);
        }

        return new XmlResponse($this->autoresponderXml);
    }
}
