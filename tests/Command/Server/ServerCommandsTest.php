<?php

declare(strict_types=1);

namespace App\Tests\Command\Server;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Server\Components\ComponentsInstallCommand;
use App\Command\Server\Components\ComponentsListCommand;
use App\Command\Server\Extension\ExtensionCallCommand;
use App\Command\Server\Extension\ExtensionGetCommand;
use App\Command\Server\Extension\ExtensionInstallCommand;
use App\Command\Server\Extension\ExtensionListCommand;
use App\Command\Server\Extension\ExtensionUninstallCommand;
use App\Command\Server\Ip\IpAddCommand;
use App\Command\Server\Ip\IpGetCommand;
use App\Command\Server\Ip\IpListCommand;
use App\Command\Server\Ip\IpRemoveCommand;
use App\Command\Server\Ip\IpSetCommand;
use App\Command\Server\ServerAdminCommand;
use App\Command\Server\ServerExecCommand;
use App\Command\Server\ServerInfoCommand;
use App\Command\Server\ServerRefCommand;
use App\Command\Server\Service\ServiceRestartCommand;
use App\Command\Server\Service\ServiceStartCommand;
use App\Command\Server\Service\ServiceStatusCommand;
use App\Command\Server\Service\ServiceStopCommand;
use App\Command\Server\Session\SessionGetCommand;
use App\Command\Server\Session\SessionListCommand;
use App\Command\Server\Session\SessionTerminateCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use App\Tests\Support\RecordingRestGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversNothing]
final class ServerCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RecordingRestGateway $restGateway;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-server-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/config', 0o777, true);
        mkdir($this->base.'/data', 0o777, true);
        file_put_contents(
            $this->base.'/config/plead.yaml',
            "servers:\n    - host: fake.local\n      secret_key: test-key\n",
        );

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
        $this->restGateway = new RecordingRestGateway();
        $this->context = new RuntimeContext($paths, null, false, null, 0, gateway: $this->gateway, restGateway: $this->restGateway);
    }

    public function testServerInfoDisplaysEverything(): void
    {
        $this->gateway->serverInfo = [
            'server_name' => 'dellius.delta4x4.net',
            'plesk_version' => '18.0.80',
            'plesk_os' => 'Ubuntu',
            'os_release' => '22.04.4',
            'plesk_build' => '1800240510.34',
            'cpu' => '2',
            'uptime' => '42331',
            'load_avg' => ['l1' => '0.10', 'l5' => '0.20', 'l15' => '0.15'],
            'objects' => ['domains' => '34', 'mail_boxes' => '42'],
            'updates' => ['available_update' => '18.0.81', 'security_updates' => '0'],
        ];

        $tester = $this->tester(new ServerInfoCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('dellius.delta4x4.net', $tester->getDisplay());
        self::assertStringContainsString('18.0.80', $tester->getDisplay());
        self::assertStringContainsString('Ubuntu', $tester->getDisplay());
        self::assertStringContainsString('0.10', $tester->getDisplay());
        self::assertStringContainsString('34', $tester->getDisplay());
        self::assertStringContainsString('18.0.81', $tester->getDisplay());
    }

    public function testSessionListShowsSessions(): void
    {
        $this->gateway->sessions = [
            [
                'id' => 'abc123',
                'type' => 'admin',
                'ip_address' => '192.0.2.1',
                'login' => 'admin',
                'login_time' => '2026-08-20T08:00:00',
                'idle' => '2026-08-20T08:30:00',
            ],
        ];

        $tester = $this->tester(new SessionListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('abc123', $tester->getDisplay());
        self::assertStringContainsString('admin', $tester->getDisplay());
    }

    public function testSessionListEmpty(): void
    {
        $tester = $this->tester(new SessionListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No open sessions', $tester->getDisplay());
    }

    public function testSessionGetFindsById(): void
    {
        $this->gateway->sessions = [
            [
                'id' => 'abc123',
                'type' => 'client',
                'ip_address' => '192.0.2.1',
                'login' => 'jdoe',
                'login_time' => '2026-08-20T08:00:00',
                'idle' => '2026-08-20T08:30:00',
            ],
        ];

        $tester = $this->tester(new SessionGetCommand());
        $tester->execute(['session-id' => 'abc123']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('abc123', $tester->getDisplay());
        self::assertStringContainsString('jdoe', $tester->getDisplay());
    }

    public function testSessionGetUnknown(): void
    {
        $this->gateway->sessions = [];

        $tester = $this->tester(new SessionGetCommand());
        $tester->execute(['session-id' => 'nope']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No session with id', $tester->getDisplay());
    }

    public function testSessionTerminateIsAuditLogged(): void
    {
        $tester = $this->tester(new SessionTerminateCommand());
        $tester->execute(['session-id' => 'abc123']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['abc123'], $this->gateway->terminatedSessions);

        $entries = $this->context->syncLogRepository()->recent();
        self::assertSame('server_session', $entries[0]['resource_type']);
        self::assertSame('ok', $entries[0]['result']);
    }

    public function testServerAdminDisplaysPersonalInformation(): void
    {
        $this->gateway->adminInfo = [
            'cname' => 'Delta 4x4',
            'pname' => 'John Doe',
            'phone' => '+49 89 123',
            'fax' => '',
            'email' => 'admin@delta4x4.net',
            'address' => 'Baker Street',
            'city' => 'Munich',
            'state' => 'Bavaria',
            'pcode' => '80333',
            'country' => 'DE',
            'locale' => 'en-US',
            'multiple_sessions' => 'true',
        ];

        $tester = $this->tester(new ServerAdminCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('John Doe', $tester->getDisplay());
        self::assertStringContainsString('admin@delta4x4.net', $tester->getDisplay());
        self::assertStringContainsString('DE', $tester->getDisplay());
    }

    public function testServiceStatusListsAllServices(): void
    {
        $this->gateway->serviceStates = [
            ['id' => 'web', 'title' => 'Web server', 'state' => 'running', 'error' => ''],
            ['id' => 'mail', 'title' => 'Mail server', 'state' => 'stopped', 'error' => 'some failure'],
        ];

        $tester = $this->tester(new ServiceStatusCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('web', $tester->getDisplay());
        self::assertStringContainsString('running', $tester->getDisplay());
        self::assertStringContainsString('some failure', $tester->getDisplay());
    }

    public function testServiceStatusFiltersById(): void
    {
        $this->gateway->serviceStates = [
            ['id' => 'web', 'title' => 'Web server', 'state' => 'running', 'error' => ''],
        ];

        $tester = $this->tester(new ServiceStatusCommand());
        $tester->execute(['service' => 'web']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('running', $tester->getDisplay());

        $tester = $this->tester(new ServiceStatusCommand());
        $tester->execute(['service' => 'unknown']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No service with id', $tester->getDisplay());
    }

    public function testServiceVerbsMutateAndAuditLog(): void
    {
        $tester = $this->tester(new ServiceStartCommand());
        $tester->execute(['service' => 'web']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertContains('start:web', $this->gateway->serviceOps);

        $tester = $this->tester(new ServiceStopCommand());
        $tester->execute(['service' => 'mail']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertContains('stop:mail', $this->gateway->serviceOps);

        $tester = $this->tester(new ServiceRestartCommand());
        $tester->execute(['service' => 'dns']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertContains('restart:dns', $this->gateway->serviceOps);

        $entries = $this->context->syncLogRepository()->recent();
        self::assertSame('server_service', $entries[0]['resource_type']);
        self::assertSame('restart', $entries[0]['action']);
        self::assertSame('ok', $entries[0]['result']);
    }

    public function testServiceVerbFailureIsReported(): void
    {
        $this->gateway->failFor = ['mail'];

        $tester = $this->tester(new ServiceStopCommand());
        $tester->execute(['service' => 'mail']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testIpListAndGet(): void
    {
        $this->gateway->ips = [
            [
                'ip_address' => '87.106.59.215',
                'netmask' => '255.255.255.0',
                'type' => 'shared',
                'interface' => 'eth0',
                'public_ip_address' => '',
            ],
            [
                'ip_address' => '192.0.2.10',
                'netmask' => '255.255.255.0',
                'type' => 'exclusive',
                'interface' => 'eth1',
                'public_ip_address' => '203.0.113.5',
            ],
        ];

        $tester = $this->tester(new IpListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('87.106.59.215', $tester->getDisplay());
        self::assertStringContainsString('exclusive', $tester->getDisplay());

        $tester = $this->tester(new IpGetCommand());
        $tester->execute(['ip' => '192.0.2.10']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('203.0.113.5', $tester->getDisplay());

        $tester = $this->tester(new IpGetCommand());
        $tester->execute(['ip' => '203.0.113.99']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No IP address', $tester->getDisplay());
    }

    public function testIpAddValidatesAndAudits(): void
    {
        $tester = $this->tester(new IpAddCommand());
        $tester->execute(['ip' => '192.0.2.50', '--netmask' => '255.255.255.0', '--type' => 'exclusive', '--interface' => 'eth0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['192.0.2.50'], $this->gateway->addedIps);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('server_ip', $entries[0]['resource_type']);
        self::assertStringContainsString('"type":"exclusive"', (string) $entries[0]['details']);

        $tester = $this->tester(new IpAddCommand());
        $tester->execute(['ip' => '192.0.2.51', '--netmask' => '255.255.255.0', '--type' => 'banana', '--interface' => 'eth0']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Invalid value for --type', $tester->getDisplay());

        $tester = $this->tester(new IpAddCommand());
        $tester->execute(['ip' => '192.0.2.51']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --netmask', $tester->getDisplay());
    }

    public function testIpRemoveAndSet(): void
    {
        $this->gateway->ips = [
            [
                'ip_address' => '192.0.2.10',
                'netmask' => '255.255.255.0',
                'type' => 'shared',
                'interface' => 'eth1',
                'public_ip_address' => '203.0.113.5',
            ],
        ];

        $tester = $this->tester(new IpSetCommand());
        $tester->execute(['ip' => '192.0.2.10', '--type' => 'exclusive']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['type' => 'exclusive'], $this->gateway->ipSets['192.0.2.10']);

        // Read-first audit: the old type is in the details.
        $details = (string) $this->context->syncLogRepository()->recent(1)[0]['details'];
        self::assertStringContainsString('"old":{"type":"shared"}', $details);
        self::assertStringContainsString('"new":{"type":"exclusive"}', $details);

        $tester = $this->tester(new IpRemoveCommand());
        $tester->execute(['ip' => '192.0.2.10']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['192.0.2.10'], $this->gateway->removedIps);
    }

    public function testIpSetRequiresProperty(): void
    {
        $tester = $this->tester(new IpSetCommand());
        $tester->execute(['ip' => '192.0.2.10']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --type', $tester->getDisplay());
    }

    public function testComponentsListAndInstall(): void
    {
        $this->gateway->components = [
            ['name' => 'plesk-core', 'version' => '18.0.80'],
            ['name' => 'fail2ban', 'version' => '1.1.1'],
        ];

        $tester = $this->tester(new ComponentsListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('plesk-core', $tester->getDisplay());
        self::assertStringContainsString('18.0.80', $tester->getDisplay());

        $tester = $this->tester(new ComponentsInstallCommand());
        $tester->execute(['component-id' => 'fail2ban', '--update-id' => 'PLESK_18_0_80']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('PLESK_18_0_80', $this->gateway->installedComponents['fail2ban']['update_id']);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('server_component', $entries[0]['resource_type']);
        self::assertSame('ok', $entries[0]['result']);

        $tester = $this->tester(new ComponentsInstallCommand());
        $tester->execute(['component-id' => 'fail2ban']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --update-id', $tester->getDisplay());
    }

    public function testExtensionListGet(): void
    {
        $this->gateway->extensions = [
            ['id' => 'wp-toolkit', 'name' => 'WP Toolkit', 'version' => '2.5.0', 'release' => '763', 'active' => true],
            ['id' => 'danami-warden', 'name' => 'Danami Warden', 'version' => '1.0.0', 'release' => '42', 'active' => false],
        ];

        $tester = $this->tester(new ExtensionListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('wp-toolkit', $tester->getDisplay());
        self::assertStringContainsString('Danami Warden', $tester->getDisplay());

        $tester = $this->tester(new ExtensionGetCommand());
        $tester->execute(['id' => 'wp-toolkit']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2.5.0', $tester->getDisplay());

        $tester = $this->tester(new ExtensionGetCommand());
        $tester->execute(['id' => 'nope']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No extension with id', $tester->getDisplay());
    }

    public function testExtensionInstallUninstallCall(): void
    {
        $tester = $this->tester(new ExtensionInstallCommand());
        $tester->execute(['id' => 'wp-toolkit']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertContains('wp-toolkit', $this->gateway->installedExtensions);

        $tester = $this->tester(new ExtensionInstallCommand());
        $tester->execute(['--url' => 'https://ext.example/package.zip']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertContains('https://ext.example/package.zip', $this->gateway->installedExtensions);

        $tester = $this->tester(new ExtensionInstallCommand());
        $tester->execute([]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide the extension', $tester->getDisplay());

        $tester = $this->tester(new ExtensionUninstallCommand());
        $tester->execute(['id' => 'wp-toolkit']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['wp-toolkit'], $this->gateway->uninstalledExtensions);

        $tester = $this->tester(new ExtensionCallCommand());
        $tester->execute([
            'id' => 'git',
            'operation' => 'remove',
            '--param' => ['domain:example.com', 'name:repo2'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('remove', $this->gateway->extensionCalls['git']['operation']);
        self::assertSame(['domain' => 'example.com', 'name' => 'repo2'], $this->gateway->extensionCalls['git']['params']);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('server_extension', $entries[0]['resource_type']);
        self::assertSame('call', $entries[0]['action']);
        self::assertStringContainsString('"operation":"remove"', (string) $entries[0]['details']);

        $tester = $this->tester(new ExtensionCallCommand());
        $tester->execute(['id' => 'git', 'operation' => 'remove', '--param' => ['nocolon']]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('name:value', $tester->getDisplay());
    }

    public function testServerRefListsCommandsWithoutId(): void
    {
        $this->restGateway->cliCommandsList = ['extension', 'domain', 'mail'];

        $tester = $this->tester(new ServerRefCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('3 CLI commands', $tester->getDisplay());
        self::assertStringContainsString('extension', $tester->getDisplay());
        self::assertStringContainsString('domain', $tester->getDisplay());
    }

    public function testServerRefShowsReferenceForId(): void
    {
        $this->restGateway->cliRefs['extension'] = [
            'allowed_commands' => [
                'call' => ['name' => 'call', 'usage' => '--call <name> <command> [<options>]', 'info' => 'Calls a command-line interface of the specified extension.'],
            ],
            'allowed_options' => [],
        ];

        $tester = $this->tester(new ServerRefCommand());
        $tester->execute(['id' => 'extension']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Reference for extension', $tester->getDisplay());
        self::assertStringContainsString('--call', $tester->getDisplay());
        self::assertStringContainsString('--call <name> <command>', $tester->getDisplay());
    }

    public function testServerExecPassesArgsAndAudits(): void
    {
        $tester = $this->tester(new ServerExecCommand());
        $tester->execute(['id' => 'extension', 'args' => ['--call', 'sslit', '--help']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('done: --call sslit --help', $tester->getDisplay());
        self::assertCount(1, $this->restGateway->cliCalls);
        self::assertSame('extension', $this->restGateway->cliCalls[0]['id']);
        self::assertSame(['--call', 'sslit', '--help'], $this->restGateway->cliCalls[0]['args']);
        self::assertTrue($this->restGateway->cliCalls[0]['failOnError']);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('server_cli', $entries[0]['resource_type']);
        self::assertSame('exec', $entries[0]['action']);
        self::assertSame('ok', $entries[0]['result']);
        self::assertStringContainsString('"args":["--call","sslit","--help"]', (string) $entries[0]['details']);
    }

    public function testServerExecNoFailOnError(): void
    {
        $tester = $this->tester(new ServerExecCommand());
        $tester->execute(['id' => 'domain', 'args' => ['--info', 'x.example'], '--no-fail-on-error' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFalse($this->restGateway->cliCalls[0]['failOnError']);
    }

    public function testServerExecFailureIsReported(): void
    {
        $this->restGateway->failFor = ['extension'];

        $tester = $this->tester(new ServerExecCommand());
        $tester->execute(['id' => 'extension', 'args' => ['--list']]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    private function tester(AbstractPleadCommand $command): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($this->context);

        return $tester;
    }
}
