<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail\Address;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Mail\Address\AddressExportCommand;
use App\Command\Mail\Address\AddressGetCommand;
use App\Command\Mail\Address\AddressListCommand;
use App\Command\Mail\Address\AddressPasswordCommand;
use App\Command\Mail\Address\AddressRemoveCommand;
use App\Command\Mail\Address\AddressRenameCommand;
use App\Command\Mail\Address\AddressSetCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversNothing]
final class AddressCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-addr-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/config', 0o777, true);
        mkdir($this->base.'/data', 0o777, true);
        file_put_contents(
            $this->base.'/config/plead.yaml',
            "servers:\n    - host: fake.local\n      secret_key: test-key\n",
        );

        $this->gateway = new RecordingGateway();
        $this->context = new RuntimeContext($this->contextPaths(), null, false, null, 0, gateway: $this->gateway);
    }

    public function testListLiveShowsMailnames(): void
    {
        $tester = $this->tester(new AddressListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('group@company.com', $tester->getDisplay());
        self::assertStringContainsString('Group mailbox', $tester->getDisplay());
    }

    public function testShowLiveShowsMailboxDetails(): void
    {
        $this->gateway->mailboxes['group@company.com'] = [
            'name' => 'group',
            'description' => 'Group mailbox',
            'mailbox_enabled' => true,
            'forwarding' => ['alice@company.com'],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressGetCommand());
        $tester->execute(['email' => 'group@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Mailbox:       enabled', $tester->getDisplay());
        self::assertStringContainsString('alice@company.com', $tester->getDisplay());
    }

    public function testShowLiveUnknownAddress(): void
    {
        $tester = $this->tester(new AddressGetCommand());
        $tester->execute(['email' => 'nobody@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No mail address', $tester->getDisplay());
    }

    public function testSetDescriptionAndDeleteRoundtrip(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $this->tester(new AddressSetCommand())->execute([
            'email' => 'user@company.com',
            '--description' => 'Holiday replacement',
        ]);
        self::assertSame('Holiday replacement', $this->gateway->mailboxes['user@company.com']['description']);

        $this->tester(new AddressRemoveCommand())->execute(['email' => 'user@company.com']);
        self::assertArrayNotHasKey('user@company.com', $this->gateway->mailboxes);
    }

    public function testSetRequiresDescription(): void
    {
        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --description', $tester->getDisplay());
    }

    public function testSetOutgoingLimitUpdatesMailbox(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--outgoing-limit' => '250']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('250', $this->gateway->mailboxes['user@company.com']['outgoing-messages-mbox-limit']);
    }

    public function testSetWithBothOptionsUpdatesBothInOneCall(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--description' => 'New desc', '--outgoing-limit' => '0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('New desc', $this->gateway->mailboxes['user@company.com']['description']);
        self::assertSame('0', $this->gateway->mailboxes['user@company.com']['outgoing-messages-mbox-limit']);
    }

    public function testSetLogsOriginalAndNewValues(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => 'Old description',
            'mailbox_enabled' => true,
            'mailbox_quota' => 268435456,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--description' => 'New description', '--quota' => '512']);

        self::assertSame(0, $tester->getStatusCode());

        $details = (string) $this->context->syncLogRepository()->recent(1)[0]['details'];
        self::assertStringContainsString('"old":{"description":"Old description","quota_mib":256', $details);
        self::assertStringContainsString('"new":{"description":"New description","quota_mib":512}', $details);
    }

    public function testSetRejectsNonNumericOutgoingLimit(): void
    {
        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--outgoing-limit' => 'many']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('non-negative integer', $tester->getDisplay());
    }

    public function testSetQuotaConvertsMiBToBytes(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--quota' => '512']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(536870912, $this->gateway->mailboxes['user@company.com']['quota']);
    }

    public function testSetWithDescriptionAndQuotaMakesBothCalls(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--description' => 'Desc', '--quota' => '128']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('Desc', $this->gateway->mailboxes['user@company.com']['description']);
        self::assertSame(134217728, $this->gateway->mailboxes['user@company.com']['quota']);
    }

    public function testSetRejectsZeroOrNonNumericQuota(): void
    {
        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--quota' => '0']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('positive integer', $tester->getDisplay());

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--quota' => 'huge']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    public function testSetAntivirValidatesServerEnum(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--antivir' => 'inout']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('inout', $this->gateway->mailboxes['user@company.com']['antivir']);

        $tester = $this->tester(new AddressSetCommand());
        $tester->execute(['email' => 'user@company.com', '--antivir' => 'on']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Invalid value for --antivir', $tester->getDisplay());
    }

    public function testRenameMovesAddressOnServerAndLocally(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        // Local rows plead tracks under the old address.
        $this->context->autoReplyRepository()->upsert('user@company.com', 'message', '2026-01-01T00:00:00+00:00', '2026-12-31T23:59:59+00:00');
        $this->context->mailGroupRepository()->upsertActive('user@company.com', 'alice@company.com');
        $this->context->mailAliasRepository()->upsertActive('user@company.com', 'info@company.com');

        $tester = $this->tester(new AddressRenameCommand());
        $tester->execute(['email' => 'user@company.com', 'new-name' => 'newuser']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('newuser', $this->gateway->renames['user@company.com']);
        self::assertStringContainsString('newuser@company.com', $tester->getDisplay());

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('rename', $entries[0]['action']);
        self::assertStringContainsString('"from":"user@company.com"', (string) $entries[0]['details']);
        self::assertStringContainsString('"to":"newuser@company.com"', (string) $entries[0]['details']);

        self::assertNull($this->context->autoReplyRepository()->find('user@company.com'));
        self::assertNotNull($this->context->autoReplyRepository()->find('newuser@company.com'));
        self::assertSame([], $this->context->mailGroupRepository()->activeRecipients('user@company.com'));
        self::assertSame(['alice@company.com'], $this->context->mailGroupRepository()->activeRecipients('newuser@company.com'));
        self::assertSame([], $this->context->mailAliasRepository()->activeAliases('user@company.com'));
        self::assertSame(['info@company.com'], $this->context->mailAliasRepository()->activeAliases('newuser@company.com'));
    }

    public function testRenameRejectsFullEmailAsNewName(): void
    {
        $tester = $this->tester(new AddressRenameCommand());
        $tester->execute(['email' => 'user@company.com', 'new-name' => 'newuser@company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('local part only', $tester->getDisplay());
    }

    public function testRenameDryRunDoesNotTouchServerOrLocalState(): void
    {
        $gateway = new RecordingGateway(dryRunMode: true);
        $context = new RuntimeContext($this->contextPaths(), null, true, null, 0, gateway: $gateway);
        $command = new AddressRenameCommand();
        $command->setContext($context);
        $tester = new CommandTester($command);

        $tester->execute(['email' => 'user@company.com', 'new-name' => 'newuser']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('(dry-run)', $tester->getDisplay());
        self::assertSame([], $gateway->renames);
    }

    public function testPasswordExplicitAndGenerated(): void
    {
        $tester = $this->tester(new AddressPasswordCommand());
        $tester->execute(['email' => 'user@company.com', '--password' => 'hunter2']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('hunter2', $this->gateway->mailboxes['user@company.com']['password']);

        $tester = $this->tester(new AddressPasswordCommand());
        $tester->execute(['email' => 'user@company.com', '--generate' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Generated password', $tester->getDisplay());
        self::assertNotSame('hunter2', $this->gateway->mailboxes['user@company.com']['password']);
    }

    public function testPasswordRequiresInput(): void
    {
        $tester = $this->tester(new AddressPasswordCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --password or use --generate', $tester->getDisplay());
    }

    public function testMutationsAreAuditLogged(): void
    {
        $this->gateway->mailboxes['user@company.com'] = [
            'name' => 'user',
            'description' => '',
            'mailbox_enabled' => true,
            'forwarding' => [],
            'autoresponder_enabled' => false,
        ];

        $this->tester(new AddressSetCommand())->execute(['email' => 'user@company.com', '--description' => 'Desc']);

        $entries = $this->context->syncLogRepository()->recent();
        self::assertSame('mail_address', $entries[0]['resource_type']);
        self::assertSame('ok', $entries[0]['result']);
    }

    public function testExportCsv(): void
    {
        $this->gateway->mailboxes['group@company.com'] = [
            'name' => 'group',
            'description' => 'Group mailbox',
            'mailbox_enabled' => true,
            'forwarding' => ['alice@company.com', 'bob@company.com'],
            'autoresponder_enabled' => false,
        ];

        $tester = $this->tester(new AddressExportCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('group@company.com', $tester->getDisplay());
        self::assertStringContainsString('"Group mailbox"', $tester->getDisplay());
        self::assertStringContainsString('true', $tester->getDisplay());
        self::assertStringContainsString('2', $tester->getDisplay());
    }

    public function testExportJson(): void
    {
        $this->gateway->mailboxes['group@company.com'] = [
            'name' => 'group',
            'description' => '',
            'mailbox_enabled' => false,
            'forwarding' => [],
            'autoresponder_enabled' => true,
        ];

        $tester = $this->tester(new AddressExportCommand());
        $tester->execute(['--format' => 'json']);

        self::assertSame(0, $tester->getStatusCode());

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame('group@company.com', $decoded[0]['address']);
        self::assertSame('false', $decoded[0]['mailbox_enabled']);
        self::assertSame('true', $decoded[0]['autoresponder_enabled']);
    }

    public function testExportToFile(): void
    {
        $target = $this->base.'/export.csv';

        $tester = $this->tester(new AddressExportCommand());
        $tester->execute(['--output' => $target]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Exported', $tester->getDisplay());
        self::assertStringContainsString('group@company.com', (string) file_get_contents($target));
    }

    public function testExportRejectsUnknownFormat(): void
    {
        $tester = $this->tester(new AddressExportCommand());
        $tester->execute(['--format' => 'xml']);

        self::assertNotSame(0, $tester->getStatusCode());
    }

    private function contextPaths(): PathProviderInterface
    {
        return new class($this->base) implements PathProviderInterface {
            public function __construct(private readonly string $base) {}

            public function configHome(): string
            {
                return $this->base.'/config';
            }

            public function configDirs(): array
            {
                return [$this->base.'/config'];
            }

            public function configPaths(): array
            {
                return [$this->base.'/config/plead.yaml'];
            }

            public function dataHome(): string
            {
                return $this->base.'/data';
            }

            public function dataDir(): string
            {
                return $this->base.'/data';
            }

            public function cacheHome(): string
            {
                return $this->base.'/cache';
            }

            public function logFile(): string
            {
                return $this->base.'/data/plead.log';
            }
        };
    }

    private function tester(AbstractPleadCommand $command): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($this->context);

        return $tester;
    }
}
