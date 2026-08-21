<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail\Group;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Mail\Group\GroupAddCommand;
use App\Command\Mail\Group\GroupGetCommand;
use App\Command\Mail\Group\GroupListCommand;
use App\Command\Mail\Group\GroupRemoveCommand;
use App\Command\Mail\Group\GroupSetCommand;
use App\Config\ConfigFile;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversNothing]
final class GroupCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;
    private PathProviderInterface $paths;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-mail-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/config', 0o777, true);
        mkdir($this->base.'/data', 0o777, true);
        file_put_contents(
            $this->base.'/config/plead.yaml',
            "servers:\n    - host: fake.local\n      secret_key: test-key\n",
        );

        $this->paths = new class($this->base) implements PathProviderInterface {
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

        $this->gateway = new RecordingGateway();
        $this->context = new RuntimeContext($this->paths, null, false, null, 0, gateway: $this->gateway);
    }

    public function testAddAndShowRoundtrip(): void
    {
        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['group@company.com']);

        $show = $this->tester(new GroupGetCommand());
        $show->execute(['email' => 'group@company.com']);

        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('alice@company.com', $show->getDisplay());
    }

    public function testRemoveReconcilesServerState(): void
    {
        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        $tester = $this->tester(new GroupRemoveCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->gateway->forwarding['group@company.com']);
        self::assertSame([], $this->context->mailGroupRepository()->activeRecipients('group@company.com'));
    }

    public function testSetReplacesWholeList(): void
    {
        $this->gateway->forwarding['group@company.com'] = ['legacy@company.com'];

        $tester = $this->tester(new GroupSetCommand());
        $tester->execute([
            'email' => 'group@company.com',
            '--recipients' => 'alice@company.com,bob@company.com',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->gateway->forwarding['group@company.com']);
    }

    public function testSetWithInvalidRecipientFails(): void
    {
        $tester = $this->tester(new GroupSetCommand());
        $tester->execute(['email' => 'group@company.com', '--recipients' => 'not-an-address']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Not a valid recipient', $tester->getDisplay());
    }

    public function testShowLocalStateAndHistory(): void
    {
        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'leaver@company.com']);

        $tester = $this->tester(new GroupRemoveCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'leaver@company.com']);

        $show = $this->tester(new GroupGetCommand());
        $show->execute(['email' => 'group@company.com', '--local' => true]);

        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('alice@company.com', $show->getDisplay());
        self::assertStringContainsString('leaver@company.com', $show->getDisplay());
        self::assertStringContainsString('Removed (history)', $show->getDisplay());
    }

    public function testAddOnFreshListAdoptsExistingRecipients(): void
    {
        $this->gateway->forwarding['group@company.com'] = ['admin@company.com', 'boss@company.com'];

        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'newhire@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['admin@company.com', 'boss@company.com', 'newhire@company.com'],
            $this->gateway->forwarding['group@company.com'],
        );
    }

    public function testShowLiveNoneWhenEmpty(): void
    {
        $show = $this->tester(new GroupGetCommand());
        $show->execute(['email' => 'group@company.com']);

        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('(none)', $show->getDisplay());
    }

    public function testListLocalShowsIndexWithCounts(): void
    {
        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        $list = $this->tester(new GroupListCommand());
        $list->execute(['--local' => true]);

        self::assertSame(0, $list->getStatusCode());
        self::assertStringContainsString('group@company.com', $list->getDisplay());
        self::assertStringContainsString('1', $list->getDisplay());
    }

    public function testFailureKeepsListPendingAndReports(): void
    {
        $this->gateway->failFor = ['group@company.com'];

        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('could not be reconciled', $tester->getDisplay());
        self::assertSame(['group@company.com'], $this->context->mailGroupRepository()->unreconciledLists());

        $results = array_column($this->context->syncLogRepository()->recent(), 'result');
        self::assertContains('error:boom', $results);
    }

    public function testFailedAddIsRetriedByReconcile(): void
    {
        $this->gateway->failFor = ['group@company.com'];

        $tester = $this->tester(new GroupAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        $this->gateway->failFor = [];
        $this->context->reconcilerMail()->reconcileAll();

        self::assertSame(['alice@company.com'], $this->gateway->forwarding['group@company.com']);
        self::assertSame([], $this->context->mailGroupRepository()->unreconciledLists());
    }

    public function testSetRuleComputesRecipientsFromLiveDomain(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
            ['id' => 12, 'name' => 'pbx', 'description' => ''],
            ['id' => 13, 'name' => 'all', 'description' => ''],
        ];

        $tester = $this->tester(new GroupSetCommand());
        $tester->execute(['email' => 'all@company.com', '--rule' => '^(info|pbx)@']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);
        self::assertSame([], $this->context->mailGroupRepository()->unreconciledLists());
    }

    public function testSetRulePurgesExistingRecipientsThatDoNotMatch(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];
        $this->gateway->forwarding['all@company.com'] = ['info@company.com', 'leaver@company.com'];

        $tester = $this->tester(new GroupSetCommand());
        $tester->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);
    }

    public function testSetRuleCombinesWithRecipients(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];

        $tester = $this->tester(new GroupSetCommand());
        $tester->execute([
            'email' => 'all@company.com',
            '--rule' => '^(info)@',
            '--recipients' => 'consultant@external.com',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['alice@company.com', 'consultant@external.com'],
            $this->gateway->forwarding['all@company.com'],
        );
    }

    public function testSetRulePushesPendingIntentsAfterDryRun(): void
    {
        $paths = $this->paths;
        $dryGateway = new RecordingGateway(true);
        $dryGateway->domains = [['id' => 1, 'name' => 'company.com']];
        $dryGateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];
        $dryContext = new RuntimeContext($paths, null, true, null, 0, gateway: $dryGateway);

        $dry = $this->tester(new GroupSetCommand(), $dryContext);
        $dry->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);
        self::assertStringContainsString('would be updated (dry-run)', $dry->getDisplay());

        // The dry-run left intents in the local state; the real run must push
        // them to Plesk instead of reporting a no-op.
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];

        $real = $this->tester(new GroupSetCommand());
        $real->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        self::assertSame(0, $real->getStatusCode());
        self::assertStringNotContainsString('already match the rule', $real->getDisplay());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);
        self::assertSame([], $this->context->mailGroupRepository()->unreconciledLists());
    }

    public function testSetRuleReportsNoopOnlyWhenFullyReconciled(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];

        $first = $this->tester(new GroupSetCommand());
        $first->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);

        // List is clean and matches: a true no-op.
        $second = $this->tester(new GroupSetCommand());
        $second->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('already match the rule', $second->getDisplay());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);
    }

    public function testSetRuleWithoutOptionsAndConfigFails(): void
    {
        $tester = $this->tester(new GroupSetCommand());
        $tester->execute(['email' => 'all@company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --recipients or --rule', $tester->getDisplay());
    }

    public function testSetRuleFallsBackToConfigEntry(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];
        file_put_contents($this->base.'/config/plead.yaml', implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'mail:',
            '    group:',
            '        - address: all@company.com',
            "          pattern: '^(info)@'",
        ]));

        $tester = $this->tester(new GroupSetCommand());
        $tester->execute(['email' => 'all@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);
    }

    public function testSetRuleReportsNoopWhenAlreadyInSync(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];

        $first = $this->tester(new GroupSetCommand());
        $first->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        $second = $this->tester(new GroupSetCommand());
        $second->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        self::assertSame(0, $second->getStatusCode());
        self::assertStringContainsString('already match the rule', $second->getDisplay());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['all@company.com']);
    }

    public function testSetRuleWithWriteConfigPersistsEntry(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new GroupSetCommand(), $context);
        $tester->execute([
            'email' => 'all@company.com',
            '--rule' => '^(info)@',
            '--recipients' => 'consultant@external.com',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['alice@company.com', 'consultant@external.com'],
            $this->gateway->forwarding['all@company.com'],
        );

        // The definition lands in the config file and still validates.
        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertSame([
            [
                'address' => 'all@company.com',
                'pattern' => '^(info)@',
                'recipients' => ['consultant@external.com'],
            ],
        ], $raw['mail']['group']);
        self::assertStringContainsString('written to', $tester->getDisplay());
        $this->context->configLoader()->load($this->base.'/config/plead.yaml');
    }

    public function testWriteConfigReplacesExistingEntry(): void
    {
        file_put_contents($this->base.'/config/plead.yaml', implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'mail:',
            '    group:',
            '        - address: all@company.com',
            "          pattern: '^(old)@'",
        ]));
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new GroupSetCommand(), $context);
        $tester->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        self::assertSame(0, $tester->getStatusCode());
        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertCount(1, $raw['mail']['group']);
        self::assertSame('^(info)@', $raw['mail']['group'][0]['pattern']);
    }

    public function testSetWithoutWriteConfigLeavesFileUntouched(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'info', 'description' => ''],
        ];
        $before = file_get_contents($this->base.'/config/plead.yaml');

        $tester = $this->tester(new GroupSetCommand());
        $tester->execute(['email' => 'all@company.com', '--rule' => '^(info)@']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame($before, file_get_contents($this->base.'/config/plead.yaml'));
    }

    private function tester(AbstractPleadCommand $command, ?RuntimeContext $context = null): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($context ?? $this->context);

        return $tester;
    }
}
