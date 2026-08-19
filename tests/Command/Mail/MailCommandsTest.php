<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Mail\MailAddCommand;
use App\Command\Mail\MailGetCommand;
use App\Command\Mail\MailListCommand;
use App\Command\Mail\MailRemoveCommand;
use App\Command\Mail\MailSetCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MailCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/plead-mail-cmd-' . bin2hex(random_bytes(4));
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

    public function testAddAndGetRoundtrip(): void
    {
        $tester = $this->tester(new MailAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['group@company.com']);

        $get = $this->tester(new MailGetCommand());
        $get->execute(['email' => 'group@company.com']);

        self::assertSame(0, $get->getStatusCode());
        self::assertStringContainsString('alice@company.com', $get->getDisplay());
    }

    public function testRemoveReconcilesServerState(): void
    {
        $tester = $this->tester(new MailAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        $tester = $this->tester(new MailRemoveCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([], $this->gateway->forwarding['group@company.com']);
        self::assertSame([], $this->context->mailGroupRepository()->activeRecipients('group@company.com'));
    }

    public function testSetReplacesWholeList(): void
    {
        $this->gateway->forwarding['group@company.com'] = ['legacy@company.com'];

        $tester = $this->tester(new MailSetCommand());
        $tester->execute([
            'email' => 'group@company.com',
            '--recipients' => 'alice@company.com,bob@company.com',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->gateway->forwarding['group@company.com']);
    }

    public function testSetWithInvalidRecipientFails(): void
    {
        $tester = $this->tester(new MailSetCommand());
        $tester->execute(['email' => 'group@company.com', '--recipients' => 'not-an-address']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Not a valid recipient', $tester->getDisplay());
    }

    public function testListShowsLocalStateAndHistory(): void
    {
        $tester = $this->tester(new MailAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'alice@company.com']);
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'leaver@company.com']);

        $tester = $this->tester(new MailRemoveCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'leaver@company.com']);

        $list = $this->tester(new MailListCommand());
        $list->execute(['email' => 'group@company.com']);

        self::assertSame(0, $list->getStatusCode());
        self::assertStringContainsString('alice@company.com', $list->getDisplay());
        self::assertStringContainsString('leaver@company.com', $list->getDisplay());
        self::assertStringContainsString('Removed (history)', $list->getDisplay());
    }

    public function testAddOnFreshListAdoptsExistingRecipients(): void
    {
        $this->gateway->forwarding['group@company.com'] = ['admin@company.com', 'boss@company.com'];

        $tester = $this->tester(new MailAddCommand());
        $tester->execute(['email' => 'group@company.com', 'recipient' => 'newhire@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['admin@company.com', 'boss@company.com', 'newhire@company.com'],
            $this->gateway->forwarding['group@company.com'],
        );
    }

    public function testGetShowsNoneWhenEmpty(): void
    {
        $get = $this->tester(new MailGetCommand());
        $get->execute(['email' => 'group@company.com']);

        self::assertSame(0, $get->getStatusCode());
        self::assertStringContainsString('(none)', $get->getDisplay());
    }
}
