<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail\Alias;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Mail\Alias\AliasAddCommand;
use App\Command\Mail\Alias\AliasGetCommand;
use App\Command\Mail\Alias\AliasListCommand;
use App\Command\Mail\Alias\AliasRemoveCommand;
use App\Command\Mail\Alias\AliasSetCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AliasCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/plead-alias-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/config', 0777, true);
        mkdir($this->base . '/data', 0777, true);
        file_put_contents(
            $this->base . '/config/plead.yaml',
            "plesk:\n    host: fake.local\n    secret_key: test-key\n",
        );

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
        $this->context = new RuntimeContext($paths, null, false, null, 0, gateway: $this->gateway);
    }

    private function tester(AbstractPleadCommand $command): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($this->context);

        return $tester;
    }

    public function testAddAndShowRoundtrip(): void
    {
        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@company.com']);

        // The server stores aliases as local parts; the command normalizes.
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['info'], $this->gateway->aliases['user@company.com']);

        $show = $this->tester(new AliasGetCommand());
        $show->execute(['email' => 'user@company.com']);

        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('info@company.com', $show->getDisplay());
    }

    public function testAddAcceptsLocalPartAndRejectsCrossDomain(): void
    {
        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['info'], $this->gateway->aliases['user@company.com']);

        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@other.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('same domain', $tester->getDisplay());
    }

    public function testRemoveReconcilesServerState(): void
    {
        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@company.com']);

        $tester = $this->tester(new AliasRemoveCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->gateway->aliases['user@company.com']);
        self::assertSame([], $this->context->mailAliasRepository()->activeAliases('user@company.com'));
    }

    public function testSetReplacesWholeList(): void
    {
        $this->gateway->aliases['user@company.com'] = ['legacy'];

        $tester = $this->tester(new AliasSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--aliases' => 'info@company.com,sales@company.com',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['info', 'sales'], $this->gateway->aliases['user@company.com']);
    }

    public function testSetWithEmptyAliasesFails(): void
    {
        $tester = $this->tester(new AliasSetCommand());
        $tester->execute(['email' => 'user@company.com', '--aliases' => '']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('must contain at least one', $tester->getDisplay());
    }

    public function testShowLocalStateAndHistory(): void
    {
        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info']);
        $tester->execute(['email' => 'user@company.com', 'alias' => 'sales']);

        $tester = $this->tester(new AliasRemoveCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'sales']);

        $show = $this->tester(new AliasGetCommand());
        $show->execute(['email' => 'user@company.com', '--local' => true]);

        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('info@company.com', $show->getDisplay());
        self::assertStringContainsString('sales@company.com', $show->getDisplay());
        self::assertStringContainsString('Removed (history)', $show->getDisplay());
    }

    public function testAddOnFreshMailboxAdoptsExistingAliases(): void
    {
        $this->gateway->aliases['user@company.com'] = ['info', 'contact'];

        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'newalias']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['info', 'contact', 'newalias'],
            $this->gateway->aliases['user@company.com'],
        );
    }

    public function testShowLiveNoneWhenEmpty(): void
    {
        $show = $this->tester(new AliasGetCommand());
        $show->execute(['email' => 'user@company.com']);

        self::assertSame(0, $show->getStatusCode());
        self::assertStringContainsString('(none)', $show->getDisplay());
    }

    public function testListLocalShowsIndexWithCounts(): void
    {
        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@company.com']);

        $list = $this->tester(new AliasListCommand());
        $list->execute(['--local' => true]);

        self::assertSame(0, $list->getStatusCode());
        self::assertStringContainsString('user@company.com', $list->getDisplay());
        self::assertStringContainsString('1', $list->getDisplay());
    }

    public function testFailureKeepsMailboxPendingAndReports(): void
    {
        $this->gateway->failFor = ['user@company.com'];

        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('could not be reconciled', $tester->getDisplay());
        self::assertSame(['user@company.com'], $this->context->mailAliasRepository()->unreconciledLists());

        $results = array_column($this->context->syncLogRepository()->recent(), 'result');
        self::assertContains('error:boom', $results);
    }

    public function testFailedAddIsRetriedByReconcile(): void
    {
        $this->gateway->failFor = ['user@company.com'];

        $tester = $this->tester(new AliasAddCommand());
        $tester->execute(['email' => 'user@company.com', 'alias' => 'info@company.com']);

        $this->gateway->failFor = [];
        $this->context->reconcilerAlias()->reconcile('user@company.com');

        self::assertSame(['info'], $this->gateway->aliases['user@company.com']);
        self::assertSame([], $this->context->mailAliasRepository()->unreconciledLists());
    }
}
