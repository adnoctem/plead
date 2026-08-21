<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Application\RuntimeContext;
use App\Command\WatchCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversNothing]
final class WatchCommandTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is required for the watch command.');
        }

        $this->base = sys_get_temp_dir().'/plead-watch-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/config', 0o777, true);
        mkdir($this->base.'/data', 0o777, true);
        file_put_contents($this->base.'/config/plead.yaml', implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'mail:',
            '    group:',
            '        - address: all@company.com',
            "          pattern: '^(info|noreply)@'",
        ]));

        $paths = new class($this->base) implements PathProviderInterface {
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
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'bob', 'description' => ''],
            ['id' => 12, 'name' => 'info', 'description' => ''],
            ['id' => 13, 'name' => 'noreply', 'description' => ''],
        ];
        $this->context = new RuntimeContext($paths, null, false, null, 0, gateway: $this->gateway);
    }

    public function testWatchAppliesRuleDrivenGroupsAndStopsOnSignal(): void
    {
        $killer = $this->spawnKiller(2.0);

        $command = new WatchCommand();
        $command->setContext($this->context);
        $tester = new CommandTester($command);
        $tester->execute(['--interval' => '60']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Watching mail state and rule-driven groups', $tester->getDisplay());
        self::assertStringContainsString('Stopped.', $tester->getDisplay());
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->gateway->forwarding['all@company.com']);
        self::assertSame([], $this->context->mailGroupRepository()->unreconciledLists());

        $this->reap($killer);
    }

    public function testWatchSecondPassIsNoopWithoutChanges(): void
    {
        $killer = $this->spawnKiller(2.0);

        $command = new WatchCommand();
        $command->setContext($this->context);
        $tester = new CommandTester($command);
        $tester->execute(['--interval' => '60']);

        $this->reap($killer);

        // Rule state is already converged: a second run records no new intents.
        $before = count($this->context->syncLogRepository()->all('mail_group'));

        $killer = $this->spawnKiller(2.0);
        $tester->execute(['--interval' => '60']);
        $this->reap($killer);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame($before, count($this->context->syncLogRepository()->all('mail_group')));
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->gateway->forwarding['all@company.com']);
    }

    /**
     * Spawn a child that SIGTERMs this process after $seconds. The watch loop
     * must already be running (handlers installed) when the signal lands, so
     * the handle is only reaped (proc_close) after the command returned -
     * reaping before execute() would block until the SIGTERM arrives while no
     * handler exists yet, silently killing the test process.
     *
     * @return resource
     */
    private function spawnKiller(float $seconds)
    {
        $pid = getmypid();
        $script = sprintf(
            'usleep(%d); posix_kill((int) $argv[1], SIGTERM);',
            (int) ($seconds * 1_000_000),
        );

        $process = proc_open(
            [PHP_BINARY, '-r', $script, (string) $pid],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            self::fail('Unable to spawn the SIGTERM killer process.');
        }

        return $process;
    }

    /** @param resource $process */
    private function reap($process): void
    {
        proc_close($process);
    }
}
