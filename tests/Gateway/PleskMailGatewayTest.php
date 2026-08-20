<?php

declare(strict_types=1);

namespace App\Tests\Gateway;

use App\Gateway\PleskMailGateway;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use PleskX\Api\Client;
use PleskX\Api\Exception;
use PleskX\Api\XmlResponse;

/**
 * @internal
 */
#[CoversNothing]
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

    public function testSetMailboxPropertiesBuildsSinglePacketWithAllProperties(): void
    {
        $this->gateway->setMailboxProperties('user@company.com', [
            'description' => 'Holiday replacement',
            'outgoing-messages-mbox-limit' => '250',
        ]);

        self::assertCount(2, $this->client->requests, 'site lookup + one update/set packet');

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<mailname><name>user</name>', $packet);
        self::assertStringContainsString('<description>Holiday replacement</description>', $packet);
        self::assertStringContainsString('<outgoing-messages-mbox-limit>250</outgoing-messages-mbox-limit>', $packet);
    }

    public function testSetMailboxPropertiesSkipsEmptyPropertyList(): void
    {
        $this->gateway->setMailboxProperties('user@company.com', []);

        self::assertCount(0, $this->client->requests);
    }

    public function testSetMailboxQuotaBuildsNestedMailboxPacket(): void
    {
        $this->gateway->setMailboxQuota('user@company.com', 536870912);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<mailname><name>user</name>', $packet);
        self::assertStringContainsString('<mailbox><quota>536870912</quota></mailbox>', $packet);
        self::assertStringNotContainsString('<description>', $packet);
    }

    public function testSetMailboxPropertiesSendsAntivirAsFlatProperty(): void
    {
        $this->gateway->setMailboxProperties('user@company.com', ['antivir' => 'inout']);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><set>', $packet);
        self::assertStringContainsString('<antivir>inout</antivir>', $packet);
    }

    public function testGetServerInfoBuildsPacketAndParsesResponse(): void
    {
        $info = $this->gateway->getServerInfo();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<server><get>', $packet);
        self::assertStringContainsString('<gen_info/><stat/><updates/>', $packet);

        self::assertSame('dellius.delta4x4.net', $info['server_name']);
        self::assertSame('18.0.80', $info['plesk_version']);
        self::assertSame('Ubuntu', $info['plesk_os']);
        self::assertSame('22.04.4', $info['os_release']);
        self::assertSame('1800240510.34', $info['plesk_build']);
        self::assertSame('2', $info['cpu']);
        self::assertSame('42331', $info['uptime']);
        self::assertSame(['l1' => '0.10', 'l5' => '0.20', 'l15' => '0.15'], $info['load_avg']);
        self::assertSame('34', $info['objects']['domains']);
        self::assertSame('42', $info['objects']['mail_boxes']);
        self::assertSame('18.0.81', $info['updates']['available_update']);
        self::assertSame('0', $info['updates']['security_updates']);
    }

    public function testListSessionsBuildsPacketAndParsesSessions(): void
    {
        $sessions = $this->gateway->listSessions();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<session><get/>', $packet);

        self::assertCount(2, $sessions);
        self::assertSame('abc123', $sessions[0]['id']);
        self::assertSame('admin', $sessions[0]['login']);
        self::assertSame('admin', $sessions[0]['type']);
        self::assertSame('192.0.2.1', $sessions[0]['ip_address']);
        self::assertSame('2026-08-20T08:00:00', $sessions[0]['login_time']);
        self::assertSame('2026-08-20T08:30:00', $sessions[0]['idle']);
        self::assertSame('jdoe', $sessions[1]['login']);
    }

    public function testTerminateSessionBuildsPacket(): void
    {
        $this->gateway->terminateSession('abc123');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<session><terminate>', $packet);
        self::assertStringContainsString('<session-id>abc123</session-id>', $packet);
        self::assertStringNotContainsString('<update>', $packet);
    }

    public function testSetDomainStatusBuildsGenSetupPacket(): void
    {
        $this->gateway->setDomainStatus('delta4x4.net', 16);

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<webspace><set>', $packet);
        self::assertStringContainsString('<filter><name>delta4x4.net</name></filter>', $packet);
        self::assertStringContainsString('<values><gen_setup><status>16</status>', $packet);
    }

    public function testGetAdminInfoBuildsPacketAndParses(): void
    {
        $admin = $this->gateway->getAdminInfo();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<server><get><admin/>', $packet);

        self::assertSame('John Doe', $admin['pname']);
        self::assertSame('Delta 4x4', $admin['cname']);
        self::assertSame('admin@delta4x4.net', $admin['email']);
        self::assertSame('DE', $admin['country']);
        self::assertSame('en-US', $admin['locale']);
        self::assertSame('true', $admin['multiple_sessions']);
    }

    public function testListServiceStatesBuildsPacketAndParses(): void
    {
        $services = $this->gateway->listServiceStates();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<server><get><services_state/>', $packet);

        self::assertCount(2, $services);
        self::assertSame('web', $services[0]['id']);
        self::assertSame('running', $services[0]['state']);
        self::assertSame('Web server', $services[0]['title']);
        self::assertSame('mail', $services[1]['id']);
        self::assertSame('stopped', $services[1]['state']);
        self::assertSame('some failure', $services[1]['error']);
    }

    public function testManageServiceBuildsSrvManPacket(): void
    {
        $this->gateway->manageService('mail', 'restart');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<server><srv_man>', $packet);
        self::assertStringContainsString('<id>mail</id>', $packet);
        self::assertStringContainsString('<operation>restart</operation>', $packet);
        self::assertStringNotContainsString('<get>', $packet);
    }

    public function testListIpsBuildsPacketAndParses(): void
    {
        $ips = $this->gateway->listIps();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<ip><get/>', $packet);

        self::assertCount(2, $ips);
        self::assertSame('87.106.59.215', $ips[0]['ip_address']);
        self::assertSame('shared', $ips[0]['type']);
        self::assertSame('eth0', $ips[0]['interface']);
        self::assertSame('192.0.2.10', $ips[1]['ip_address']);
        self::assertSame('203.0.113.5', $ips[1]['public_ip_address']);
    }

    public function testAddIpBuildsPacket(): void
    {
        $this->gateway->addIp('192.0.2.50', '255.255.255.0', 'exclusive', 'eth0');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<ip><add>', $packet);
        self::assertStringContainsString('<ip_address>192.0.2.50</ip_address>', $packet);
        self::assertStringContainsString('<netmask>255.255.255.0</netmask>', $packet);
        self::assertStringContainsString('<type>exclusive</type>', $packet);
        self::assertStringContainsString('<interface>eth0</interface>', $packet);
    }

    public function testRemoveIpBuildsDelPacket(): void
    {
        $this->gateway->removeIp('192.0.2.50');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<ip><del>', $packet);
        self::assertStringContainsString('<filter><ip_address>192.0.2.50</ip_address></filter>', $packet);
    }

    public function testSetIpBuildsPacket(): void
    {
        $this->gateway->setIp('192.0.2.50', ['type' => 'exclusive', 'public_ip_address' => '203.0.113.9']);

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<ip><set>', $packet);
        self::assertStringContainsString('<filter><ip_address>192.0.2.50</ip_address></filter>', $packet);
        self::assertStringContainsString('<type>exclusive</type>', $packet);
        self::assertStringContainsString('<public_ip_address>203.0.113.9</public_ip_address>', $packet);
    }

    public function testListComponentsBuildsPacketAndParses(): void
    {
        $components = $this->gateway->listComponents();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<server><get><components/>', $packet);

        self::assertCount(2, $components);
        self::assertSame('plesk-core', $components[0]['name']);
        self::assertSame('18.0.80', $components[0]['version']);
    }

    public function testInstallComponentBuildsUpdaterPacket(): void
    {
        $this->gateway->installComponent('fail2ban', 'PLESK_18_0_80');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<updater><install-component>', $packet);
        self::assertStringContainsString('<update-id>PLESK_18_0_80</update-id>', $packet);
        self::assertStringContainsString('<component-id>fail2ban</component-id>', $packet);
    }

    public function testAddSiteBuildsVrtHstPacket(): void
    {
        $this->gateway->addSite('new.domain.com', 'vrt_hst', 'company.com', 'A new domain', ['ftp_login' => 'user'], null);

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<site><add>', $packet);
        self::assertStringContainsString('<gen_setup><name>new.domain.com</name>', $packet);
        self::assertStringContainsString('<htype>vrt_hst</htype>', $packet);
        self::assertStringContainsString('<webspace-name>company.com</webspace-name>', $packet);
        self::assertStringContainsString('<hosting><vrt_hst><property><name>ftp_login</name><value>user</value>', $packet);
    }

    public function testAddSiteBuildsForwardingPacket(): void
    {
        $this->gateway->addSite('fwd.domain.com', 'std_fwd', null, null, [], 'https://target.example');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<htype>std_fwd</htype>', $packet);
        self::assertStringContainsString('<hosting><std_fwd><dest_url>https://target.example</dest_url>', $packet);
    }

    public function testAddSiteBuildsNoneHostingPacket(): void
    {
        $this->gateway->addSite('bare.domain.com', 'none', null, null, [], null);

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<htype>none</htype>', $packet);
        self::assertStringContainsString('<hosting><none/>', $packet);
    }

    public function testRemoveSiteBuildsDelPacket(): void
    {
        $this->gateway->removeSite('old.domain.com');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<site><del>', $packet);
        self::assertStringContainsString('<filter><name>old.domain.com</name></filter>', $packet);
    }

    public function testGetSiteTrafficBuildsPacketAndParses(): void
    {
        $rows = $this->gateway->getSiteTraffic('delta4x4.net', '2026-08-01', '2026-08-20');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<site><get_traffic>', $packet);
        self::assertStringContainsString('<filter><name>delta4x4.net</name></filter>', $packet);
        self::assertStringContainsString('<since_date>2026-08-01</since_date>', $packet);
        self::assertStringContainsString('<to_date>2026-08-20</to_date>', $packet);

        self::assertCount(1, $rows);
        self::assertSame('2026-08-19', $rows[0]['date']);
        self::assertSame('100', $rows[0]['http_in']);
        self::assertSame('8', $rows[0]['pop3_imap_out']);
    }

    public function testSetSiteTrafficBuildsPacketWithResolvedDomId(): void
    {
        $this->gateway->setSiteTraffic('company.com', '2026-08-19', ['smtp_in' => 5, 'smtp_out' => 6]);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<site><set_traffic>', $packet);
        self::assertStringContainsString('<dom_id>17</dom_id>', $packet);
        self::assertStringContainsString('<date>2026-08-19</date>', $packet);
        self::assertStringContainsString('<smtp_in>5</smtp_in>', $packet);
        self::assertStringContainsString('<smtp_out>6</smtp_out>', $packet);
    }

    public function testGetHostingDescriptorBuildsPacketAndParses(): void
    {
        $properties = $this->gateway->getHostingDescriptor('delta4x4.net');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<site><get-physical-hosting-descriptor>', $packet);
        self::assertStringContainsString('<filter><name>delta4x4.net</name></filter>', $packet);

        self::assertCount(2, $properties);
        self::assertSame('ftp_login', $properties[0]['name']);
        self::assertSame('string', $properties[0]['type']);
        self::assertSame('user', $properties[0]['default']);
        self::assertSame('FTP login', $properties[0]['label']);
    }

    public function testListExtensionsBuildsPacketAndParses(): void
    {
        $extensions = $this->gateway->listExtensions();

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<extension><get/>', $packet);

        self::assertCount(2, $extensions);
        self::assertSame('wp-toolkit', $extensions[0]['id']);
        self::assertSame('WP Toolkit', $extensions[0]['name']);
        self::assertSame('2.5.0', $extensions[0]['version']);
        self::assertSame('763', $extensions[0]['release']);
        self::assertTrue($extensions[0]['active']);
        self::assertFalse($extensions[1]['active']);
    }

    public function testGetExtensionFiltersById(): void
    {
        $extension = $this->gateway->getExtension('wp-toolkit');

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<extension><get>', $packet);
        self::assertStringContainsString('<filter><id>wp-toolkit</id></filter>', $packet);

        self::assertNotNull($extension);
        self::assertSame('WP Toolkit', $extension['name']);
    }

    public function testInstallUninstallCallExtensionBuildPackets(): void
    {
        $this->gateway->installExtension('wp-toolkit', null);
        $packet = $this->client->requests[0];
        self::assertStringContainsString('<extension><install>', $packet);
        self::assertStringContainsString('<id>wp-toolkit</id>', $packet);

        $this->gateway->installExtension(null, 'https://ext.example/package.zip');
        $packet = $this->client->requests[1];
        self::assertStringContainsString('<url>https://ext.example/package.zip</url>', $packet);

        $this->gateway->uninstallExtension('wp-toolkit');
        $packet = $this->client->requests[2];
        self::assertStringContainsString('<extension><uninstall>', $packet);
        self::assertStringContainsString('<id>wp-toolkit</id>', $packet);
    }

    public function testCallExtensionUsesIdAsElementName(): void
    {
        // Docs shape: <extension><call><git><remove><domain>..</domain>...
        $this->gateway->callExtension('git', 'remove', [
            'domain' => 'example.com',
            'name' => 'repo2',
        ]);

        $packet = $this->client->requests[0];
        self::assertStringContainsString('<extension><call>', $packet);
        self::assertStringContainsString('<git><remove>', $packet);
        self::assertStringContainsString('<domain>example.com</domain>', $packet);
        self::assertStringContainsString('<name>repo2</name>', $packet);
        self::assertStringNotContainsString('<id>', $packet);
        self::assertStringNotContainsString('<operation>', $packet);
    }

    public function testCallExtensionRejectsInvalidElementNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway->callExtension('bad id!', 'remove');
    }

    public function testGetAliasesParsesAliasElements(): void
    {
        $this->client->aliasesXml = <<<'XML'
            <packet>
              <mail>
                <get_info>
                  <result>
                    <status>ok</status>
                    <mailname>
                      <id>42</id>
                      <name>user</name>
                      <alias>info@company.com</alias>
                      <alias>sales@company.com</alias>
                    </mailname>
                  </result>
                </get_info>
              </mail>
            </packet>
            XML;

        $aliases = $this->gateway->getAliases('user@company.com');

        self::assertSame(['info@company.com', 'sales@company.com'], $aliases);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><get_info>', $packet);
        self::assertStringContainsString('<name>user</name>', $packet);
        // The data tag is 'aliases' (plural) - verified against the live
        // server; the XSD's mailbox/forwarding/autoresponder list is wrong.
        self::assertStringContainsString('<aliases/>', $packet);
        self::assertStringNotContainsString('<alias/>', $packet);
    }

    public function testGetAliasesReturnsEmptyWhenAbsent(): void
    {
        $this->client->aliasesXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>user</name></mailname></result></get_info></mail></packet>';

        self::assertSame([], $this->gateway->getAliases('user@company.com'));
    }

    public function testGetAliasesBulkRequestsAliasesDataTag(): void
    {
        $this->client->bulkResponses = [
            '<packet><mail><get_info><result><status>ok</status><mailname><id>1</id><name>admin</name><alias>info@company.com</alias><alias>sales@company.com</alias></mailname></result></get_info></mail></packet>',
            '<packet><mail><get_info><result><status>error</status><errcode>1013</errcode><errtext>mail does not exist</errtext></result></get_info></mail></packet>',
        ];

        $aliases = $this->gateway->getAliasesBulk(['user@company.com', 'ghost@company.com']);

        self::assertSame(['info@company.com', 'sales@company.com'], $aliases['user@company.com']);
        self::assertSame([], $aliases['ghost@company.com']);
        self::assertArrayHasKey('aliases', $this->client->multiRequests[0][0]['mail']['get_info']);
    }

    public function testGetMailboxInfoRequestsMailboxUsageTag(): void
    {
        $this->client->mailboxInfoXml = <<<'XML'
            <packet>
              <mail>
                <get_info>
                  <result>
                    <status>ok</status>
                    <mailname>
                      <id>42</id>
                      <name>user</name>
                      <mailbox><enabled>true</enabled><quota>268435456</quota><usage>53909274</usage></mailbox>
                    </mailname>
                  </result>
                </get_info>
              </mail>
            </packet>
            XML;

        $info = $this->gateway->getMailboxInfo('user@company.com');

        self::assertNotNull($info);
        self::assertSame(268435456, $info['mailbox_quota']);
        self::assertSame(53909274, $info['mailbox_usage']);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mailbox-usage/>', $packet);
    }

    public function testAddAliasesBuildsUpdateAddPacket(): void
    {
        $this->gateway->addAliases('user@company.com', ['info@company.com', 'sales@company.com']);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><add>', $packet);
        self::assertStringContainsString('<site-id>17</site-id>', $packet);
        self::assertStringContainsString('<mailname><name>user</name>', $packet);
        self::assertStringContainsString('<alias>info@company.com</alias>', $packet);
        self::assertStringContainsString('<alias>sales@company.com</alias>', $packet);
    }

    public function testRemoveAliasesBuildsUpdateRemovePacket(): void
    {
        $this->gateway->removeAliases('user@company.com', ['sales@company.com']);

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><update><remove>', $packet);
        self::assertStringContainsString('<alias>sales@company.com</alias>', $packet);
    }

    public function testAliasUpdateSkipsEmptyAliasList(): void
    {
        $this->gateway->addAliases('user@company.com', []);
        $this->gateway->removeAliases('user@company.com', []);

        self::assertCount(0, $this->client->requests);
    }

    public function testRenameAddressBuildsRenamePacket(): void
    {
        $this->gateway->renameAddress('user@company.com', 'newuser');

        $packet = $this->client->requests[1];
        self::assertStringContainsString('<mail><rename>', $packet);
        self::assertStringContainsString('<site-id>17</site-id>', $packet);
        self::assertStringContainsString('<name>user</name>', $packet);
        self::assertStringContainsString('<new-name>newuser</new-name>', $packet);
        self::assertStringNotContainsString('<update>', $packet);
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
    /** @var array<int, list<array<string, mixed>>> one entry per multiRequest call */
    public array $multiRequests = [];

    public string $autoresponderXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>user</name></mailname></result></get_info></mail></packet>';

    public string $forwardingXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>group</name></mailname></result></get_info></mail></packet>';

    public string $mailboxInfoXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>group</name><mailbox><enabled>true</enabled><quota>268435456</quota><usage>53909274</usage></mailbox><forwarding><enabled>false</enabled></forwarding><autoresponder><enabled>false</enabled></autoresponder></mailname></result></get_info></mail></packet>';

    public string $aliasesXml = '<packet><mail><get_info><result><status>ok</status><mailname><id>42</id><name>group</name><alias>info@company.com</alias></mailname></result></get_info></mail></packet>';

    public string $serverInfoXml = '<packet><server><get><result><status>ok</status><gen_info><server_name>dellius.delta4x4.net</server_name></gen_info><stat><version><plesk_version>18.0.80</plesk_version><plesk_os>Ubuntu</plesk_os><os_release>22.04.4</os_release><plesk_build>1800240510.34</plesk_build></version><other><cpu>2</cpu><uptime>42331</uptime></other><load_avg><l1>0.10</l1><l5>0.20</l5><l15>0.15</l15></load_avg><objects><domains>34</domains><mail_boxes>42</mail_boxes></objects></stat><updates><available_update>18.0.81</available_update><security_updates>0</security_updates></updates></result></get></server></packet>';

    public string $sessionsXml = '<packet><session><get><result><status>ok</status><session><id>abc123</id><type>admin</type><ip-address>192.0.2.1</ip-address><login>admin</login><login-time>2026-08-20T08:00:00</login-time><idle>2026-08-20T08:30:00</idle></session><session><id>def456</id><type>client</type><ip-address>192.0.2.2</ip-address><login>jdoe</login><login-time>2026-08-20T07:00:00</login-time><idle>2026-08-20T07:05:00</idle></session></result></get></session></packet>';

    public string $adminXml = '<packet><server><get><result><status>ok</status><admin><admin_cname>Delta 4x4</admin_cname><admin_pname>John Doe</admin_pname><admin_phone>+49 89 123</admin_phone><admin_fax/><admin_email>admin@delta4x4.net</admin_email><admin_address>Baker Street</admin_address><admin_city>Munich</admin_city><admin_state>Bavaria</admin_state><admin_pcode>80333</admin_pcode><admin_country>DE</admin_country><admin_locale>en-US</admin_locale><admin_multiple_sessions>true</admin_multiple_sessions></admin></result></get></server></packet>';

    public string $servicesXml = '<packet><server><get><result><status>ok</status><services_state><srv><id>web</id><title>Web server</title><state>running</state><error/></srv><srv><id>mail</id><title>Mail server</title><state>stopped</state><error>some failure</error></srv></services_state></result></get></server></packet>';

    public string $ipsXml = '<packet><ip><get><result><status>ok</status><addresses><ip_info><ip_address>87.106.59.215</ip_address><netmask>255.255.255.0</netmask><type>shared</type><interface>eth0</interface><public_ip_address/></ip_info><ip_info><ip_address>192.0.2.10</ip_address><netmask>255.255.255.0</netmask><type>exclusive</type><interface>eth1</interface><public_ip_address>203.0.113.5</public_ip_address></ip_info></addresses></result></get></ip></packet>';

    public string $componentsXml = '<packet><server><get><result><status>ok</status><components><component><name>plesk-core</name><version>18.0.80</version></component><component><name>fail2ban</name><version>1.1.1</version></component></components></result></get></server></packet>';

    public string $siteTrafficXml = '<packet><site><get_traffic><result><status>ok</status><traffic><date>2026-08-19</date><http_in>100</http_in><http_out>200</http_out><ftp_in>10</ftp_in><ftp_out>20</ftp_out><smtp_in>5</smtp_in><smtp_out>6</smtp_out><pop3_imap_in>7</pop3_imap_in><pop3_imap_out>8</pop3_imap_out></traffic></result></get_traffic></site></packet>';

    public string $descriptorXml = '<packet><site><get-physical-hosting-descriptor><result><status>ok</status><descriptor><property><name>ftp_login</name><type>string</type><default-value>user</default-value><label>FTP login</label></property><property><name>ftp_password</name><type>password</type><default-value></default-value><label>FTP password</label></property></descriptor></result></get-physical-hosting-descriptor></site></packet>';

    public string $extensionsXml = '<packet><extension><get><result><status>ok</status><details><id>wp-toolkit</id><name>WP Toolkit</name><version>2.5.0</version><release>763</release><active>true</active></details><details><id>danami-warden</id><name>Danami Warden</name><version>1.0.0</version><release>42</release><active>false</active></details></result></get></extension></packet>';

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

    /**
     * @param \SimpleXMLElement|string $request
     */
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

        if (str_contains($xml, '<server><get><admin')) {
            return new XmlResponse($this->adminXml);
        }

        if (str_contains($xml, '<services_state')) {
            return new XmlResponse($this->servicesXml);
        }

        if (str_contains($xml, '<components')) {
            return new XmlResponse($this->componentsXml);
        }

        if (str_contains($xml, '<server><get>')) {
            return new XmlResponse($this->serverInfoXml);
        }

        if (str_contains($xml, '<server><srv_man>')) {
            return new XmlResponse('<packet><server><srv_man><result><status>ok</status><id>x</id></result></srv_man></server></packet>');
        }

        if (str_contains($xml, '<ip><get')) {
            return new XmlResponse($this->ipsXml);
        }

        if (str_contains($xml, '<ip><add') || str_contains($xml, '<ip><del') || str_contains($xml, '<ip><set')) {
            return new XmlResponse('<packet><ip><result><status>ok</status><ip_address>x</ip_address></result></ip></packet>');
        }

        if (str_contains($xml, '<updater><install-component>')) {
            return new XmlResponse('<packet><updater><install-component><result><status>ok</status></result></install-component></updater></packet>');
        }

        if (str_contains($xml, '<site><get_traffic')) {
            return new XmlResponse($this->siteTrafficXml);
        }

        if (str_contains($xml, '<site><get-physical-hosting-descriptor')) {
            return new XmlResponse($this->descriptorXml);
        }

        if (str_contains($xml, '<site><add') || str_contains($xml, '<site><del') || str_contains($xml, '<site><set_traffic')) {
            return new XmlResponse('<packet><site><result><status>ok</status><id>1</id></result></site></packet>');
        }

        if (str_contains($xml, '<extension><get')) {
            return new XmlResponse($this->extensionsXml);
        }

        if (str_contains($xml, '<extension><install') || str_contains($xml, '<extension><uninstall') || str_contains($xml, '<extension><call')) {
            return new XmlResponse('<packet><extension><result><status>ok</status></result></extension></packet>');
        }

        if (str_contains($xml, '<session><get')) {
            return new XmlResponse($this->sessionsXml);
        }

        if (str_contains($xml, '<session><terminate>')) {
            return new XmlResponse('<packet><session><terminate><result><status>ok</status></result></terminate></session></packet>');
        }

        if (str_contains($xml, '<site><get>')) {
            // Empty filter = enumerate all sites (site fallback).
            return str_contains($xml, '<filter/>')
                ? new XmlResponse($this->siteListXml)
                : new XmlResponse('<packet><site><get><result><status>ok</status><id>17</id></result></get></site></packet>');
        }

        if ($this->mailDoesNotExist) {
            // Only mail requests (not site lookups) fail this way.
            throw new Exception('mail does not exist', 1013);
        }

        if (str_contains($xml, '<aliases/>')) {
            return new XmlResponse($this->aliasesXml);
        }

        if (str_contains($xml, '<mailbox/>')) {
            // Combined get_info (mailbox+forwarding+autoresponder data tags).
            return new XmlResponse($this->mailboxInfoXml);
        }

        if (str_contains($xml, '<forwarding/>') || str_contains($xml, '<forwarding>')) {
            return new XmlResponse($this->forwardingXml);
        }

        if (str_contains($xml, '<autoresponder/>')) {
            return new XmlResponse($this->autoresponderXml);
        }

        if (str_contains($xml, '<mail><rename>')) {
            return new XmlResponse('<packet><mail><rename><result><status>ok</status></result></rename></mail></packet>');
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

    /**
     * @param list<array<string, mixed>> $requests
     *
     * @return list<\SimpleXMLElement>
     */
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
