<?php

declare(strict_types=1);

namespace App\Tests\Command\AutoReply;

use App\Application\RuntimeContext;
use App\Command\AutoReply\AutoReplySetCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AutoReplySetCommandTest extends TestCase
{
    private string $base;
    private string $messageFile;
    private RecordingGateway $gateway;
    private AutoReplySetCommand $command;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/plead-set-test-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/data', 0777, true);
        file_put_contents(
            $this->base . '/config/plead.yaml',
            "plesk:\n    host: fake.local\n    secret_key: test-key\n",
        );
        $this->messageFile = $this->base . '/message.txt';
        file_put_contents($this->messageFile, 'Bin im Urlaub & Grüße');

        $paths = new class($this->base) implements PathProviderInterface {
            public function __construct(private readonly string $base)
            {
            }

            public function configHome(): string
            {
                return $this->base . '/config';
            }

            public function configDirs(): array
            {
                return [$this->base . '/config'];
            }

            public function configPaths(): array
            {
                return [$this->base . '/config/plead.yaml'];
            }

            public function dataHome(): string
            {
                return $this->base . '/data';
            }

            public function dataDir(): string
            {
                return $this->base . '/data';
            }

            public function cacheHome(): string
            {
                return $this->base . '/cache';
            }

            public function logFile(): string
            {
                return $this->base . '/data/plead.log';
            }
        };

        $this->gateway = new RecordingGateway();
        $context = new RuntimeContext($paths, null, false, null, 0, gateway: $this->gateway);
        $this->command = new AutoReplySetCommand();
        $this->command->setContext($context);
    }

    public function testSchedulesFutureStartWithoutApplying(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('scheduled', $tester->getDisplay());
        self::assertFalse($this->gateway->wasCalledWith('user@company.com'));
    }

    public function testImmediateStartAppliesThroughReconciler(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue($this->gateway->wasCalledWith('user@company.com'));
        self::assertStringContainsString('applied', $tester->getDisplay());
    }

    public function testRejectsEndDateBeforeStartDate(): void
    {
        $tester = new CommandTester($this->command);
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
        $tester = new CommandTester($this->command);
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->base . '/does-not-exist.txt',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
    }

    public function testGatewayFailureSurfacesAsFailure(): void
    {
        $this->gateway->failFor = ['user@company.com'];

        $tester = new CommandTester($this->command);
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('could not be applied', $tester->getDisplay());
    }

    public function testDryRunReportsWouldApply(): void
    {
        $paths = new class($this->base) implements PathProviderInterface {
            public function __construct(private readonly string $base)
            {
            }

            public function configHome(): string
            {
                return $this->base . '/config';
            }

            public function configDirs(): array
            {
                return [$this->base . '/config'];
            }

            public function configPaths(): array
            {
                return [$this->base . '/config/plead.yaml'];
            }

            public function dataHome(): string
            {
                return $this->base . '/data';
            }

            public function dataDir(): string
            {
                return $this->base . '/data';
            }

            public function cacheHome(): string
            {
                return $this->base . '/cache';
            }

            public function logFile(): string
            {
                return $this->base . '/data/plead.log';
            }
        };

        $command = new AutoReplySetCommand();
        $command->setContext(new RuntimeContext($paths, null, true, null, 0, gateway: new RecordingGateway()));

        $tester = new CommandTester($command);
        $tester->execute([
            'email' => 'user@company.com',
            '--message-file' => $this->messageFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('would be applied (dry-run)', $tester->getDisplay());
    }
}
