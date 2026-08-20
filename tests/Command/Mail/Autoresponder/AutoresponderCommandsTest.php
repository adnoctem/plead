<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail\Autoresponder;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Mail\Autoresponder\AutoresponderGetCommand;
use App\Command\Mail\Autoresponder\AutoresponderListCommand;
use App\Command\Mail\Autoresponder\AutoresponderSetCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversNothing]
final class AutoresponderCommandsTest extends TestCase
{
    private string $base;
    private string $messageFile;
    private RecordingGateway $gateway;
    private RuntimeContext $context;
    private PathProviderInterface $paths;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-set-test-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/config', 0o777, true);
        mkdir($this->base.'/data', 0o777, true);
        file_put_contents(
            $this->base.'/config/plead.yaml',
            "plesk:\n    host: fake.local\n    secret_key: test-key\n",
        );
        $this->messageFile = $this->base.'/message.txt';
        file_put_contents($this->messageFile, 'Bin im Urlaub & Grüße');

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

    public function testSchedulesFutureStartWithoutApplying(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('scheduled', $tester->getDisplay());
        self::assertFalse($this->gateway->wasCalledWith('user@company.com'));
        self::assertSame('0', (string) $this->context->autoReplyRepository()->find('user@company.com')['reconciled']);
    }

    public function testImmediateStartAppliesThroughReconciler(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue($this->gateway->wasCalledWith('user@company.com'));
        self::assertStringContainsString('applied', $tester->getDisplay());
        self::assertSame('1', (string) $this->context->autoReplyRepository()->find('user@company.com')['reconciled']);
    }

    public function testRejectsEndDateBeforeStartDate(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--start-date' => '2099-01-10T08:00:00+02:00',
            '--end-date' => '2099-01-01T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('end-date must be after start-date', $tester->getDisplay());
    }

    public function testMissingMessageFileFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->base.'/does-not-exist.txt',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
    }

    public function testGatewayFailureStaysPendingForWatcher(): void
    {
        $this->gateway->failFor = ['user@company.com'];

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('could not be applied to Plesk yet', $tester->getDisplay());
        self::assertSame('0', (string) $this->context->autoReplyRepository()->find('user@company.com')['reconciled']);
    }

    public function testDisableKeepsRowAndDisablesOnPlesk(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--enabled' => 'false']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertFalse($this->gateway->autoresponders['user@company.com']);

        // The row stays: status + reconcile state carry the audit trail.
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame('1', (string) $row['reconciled']);
    }

    public function testDisableFailureStaysPendingForWatcher(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);
        $this->gateway->failFor = ['user@company.com'];

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--enabled' => 'false']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('stays pending', $tester->getDisplay());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertSame('disabled', $row['status']);
        self::assertSame('0', (string) $row['reconciled']);
    }

    public function testShowLocalDisplaysDesiredState(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        $tester = $this->tester(new AutoresponderGetCommand());
        $tester->execute(['email' => 'user@company.com', '--local' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Status:    scheduled', $tester->getDisplay());
    }

    public function testListLocalShowsAllEntries(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        $tester = $this->tester(new AutoresponderListCommand());
        $tester->execute(['--local' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('user@company.com', $tester->getDisplay());
    }

    public function testListLiveShowsOnlyEnabledOnServer(): void
    {
        $this->gateway->mailnames = [
            ['id' => 1, 'name' => 'on', 'description' => ''],
            ['id' => 2, 'name' => 'off', 'description' => ''],
        ];
        $this->gateway->autoresponders = [
            'on@company.com' => true,
            'off@company.com' => false,
        ];

        $tester = $this->tester(new AutoresponderListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('on@company.com', $tester->getDisplay());
        self::assertStringNotContainsString('off@company.com', $tester->getDisplay());
    }

    public function testDryRunReportsWouldApply(): void
    {
        $context = new RuntimeContext($this->paths, null, true, null, 0, gateway: new RecordingGateway());
        $command = new AutoresponderSetCommand();
        $command->setContext($context);

        $tester = new CommandTester($command);
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('would be applied (dry-run)', $tester->getDisplay());
    }

    private function tester(AbstractPleadCommand $command): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($this->context);

        return $tester;
    }
}
