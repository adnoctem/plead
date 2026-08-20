<?php

declare(strict_types=1);

namespace App\Tests\Command\Domain;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Domain\DomainAddCommand;
use App\Command\Domain\DomainDescriptorCommand;
use App\Command\Domain\DomainGetCommand;
use App\Command\Domain\DomainListCommand;
use App\Command\Domain\DomainRemoveCommand;
use App\Command\Domain\DomainSetCommand;
use App\Command\Domain\DomainTrafficGetCommand;
use App\Command\Domain\DomainTrafficSetCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DomainCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/plead-domain-cmd-' . bin2hex(random_bytes(4));
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

    public function testListShowsDomains(): void
    {
        $tester = $this->tester(new DomainListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('company.com', $tester->getDisplay());
    }

    public function testGetShowsEverything(): void
    {
        $this->gateway->domainsInfo['company.com'] = [
            'id' => 1,
            'name' => 'company.com',
            'ascii_name' => 'company.com',
            'status' => '0',
            'htype' => 'vrt_hst',
            'cr_date' => '2024-09-06',
            'real_size' => '100',
            'owner_login' => 'admin',
            'ip_addresses' => ['87.106.59.215'],
            'guid' => 'abc',
            'external_id' => '',
            'description' => 'Main domain',
            'admin_description' => '',
            'hosting' => ['ftp_login' => 'company', 'www_root' => '/var/www/company'],
        ];

        $tester = $this->tester(new DomainGetCommand());
        $tester->execute(['domain' => 'company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Status:           enabled', $tester->getDisplay());
        self::assertStringContainsString('Hosting type:     vrt_hst', $tester->getDisplay());
        self::assertStringContainsString('Main domain', $tester->getDisplay());
        self::assertStringContainsString('Mail addresses:   1', $tester->getDisplay());
        self::assertStringContainsString('/var/www/company', $tester->getDisplay());
    }

    public function testGetMissingDomainReportsNotFound(): void
    {
        $tester = $this->tester(new DomainGetCommand());
        $tester->execute(['domain' => 'missing.example']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No domain', $tester->getDisplay());
    }

    public function testSetUpdatesDescription(): void
    {
        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com', '--description' => 'New description']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertSame('New description', $this->gateway->domainsInfo['company.com']['description']);

        $entries = $this->context->syncLogRepository()->recent();
        self::assertSame('domain', $entries[0]['resource_type']);
        self::assertSame('ok', $entries[0]['result']);
    }

    public function testSetStatusEnablesAndDisables(): void
    {
        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com', '--status' => 'disabled']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('updated', $tester->getDisplay());
        self::assertSame(16, $this->gateway->domainStatuses['company.com']);

        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com', '--status' => 'enabled']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, $this->gateway->domainStatuses['company.com']);
    }

    public function testSetRequiresDescriptionOrStatus(): void
    {
        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --description', $tester->getDisplay());
    }

    public function testSetRejectsInvalidStatusValue(): void
    {
        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com', '--status' => 'maybe']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Invalid value for --status', $tester->getDisplay());
    }

    public function testSetFailureIsReported(): void
    {
        $this->gateway->failFor = ['company.com'];

        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com', '--description' => 'x']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testAddCreatesVirtualHostDomain(): void
    {
        $tester = $this->tester(new DomainAddCommand());
        $tester->execute([
            'domain' => 'new.domain.com',
            '--type' => 'virtual-host',
            '--parent' => 'company.com',
            '--description' => 'A new domain',
            '--property' => ['ftp_login:user', 'ftp_password:secret'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('created', $tester->getDisplay());
        self::assertSame('vrt_hst', $this->gateway->addedSites[0]['htype']);
        self::assertSame('company.com', $this->gateway->addedSites[0]['parent']);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('domain', $entries[0]['resource_type']);
        self::assertSame('add', $entries[0]['action']);
        self::assertStringContainsString('"type":"virtual-host"', (string) $entries[0]['details']);
    }

    public function testAddForwardingRequiresDestUrl(): void
    {
        $tester = $this->tester(new DomainAddCommand());
        $tester->execute(['domain' => 'fwd.domain.com', '--type' => 'forwarding']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('--dest-url', $tester->getDisplay());

        $tester = $this->tester(new DomainAddCommand());
        $tester->execute(['domain' => 'fwd.domain.com', '--type' => 'forwarding', '--dest-url' => 'https://target.example']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('std_fwd', $this->gateway->addedSites[0]['htype']);
    }

    public function testAddRejectsUnknownTypeAndBadProperty(): void
    {
        $tester = $this->tester(new DomainAddCommand());
        $tester->execute(['domain' => 'x.domain.com', '--type' => 'ftp']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('virtual-host, forwarding', $tester->getDisplay());

        $tester = $this->tester(new DomainAddCommand());
        $tester->execute(['domain' => 'x.domain.com', '--type' => 'virtual-host', '--property' => ['nocolon']]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('name:value', $tester->getDisplay());
    }

    public function testRemoveDeletesDomain(): void
    {
        $tester = $this->tester(new DomainRemoveCommand());
        $tester->execute(['domain' => 'old.domain.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['old.domain.com'], $this->gateway->removedSites);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('remove', $entries[0]['action']);
        self::assertSame('ok', $entries[0]['result']);
    }

    public function testTrafficGetDisplaysRowsAndValidatesDates(): void
    {
        $this->gateway->traffic['company.com'] = [[
            'date' => '2026-08-19',
            'http_in' => '100',
            'http_out' => '200',
            'ftp_in' => '10',
            'ftp_out' => '20',
            'smtp_in' => '5',
            'smtp_out' => '6',
            'pop3_imap_in' => '7',
            'pop3_imap_out' => '8',
        ]];

        $tester = $this->tester(new DomainTrafficGetCommand());
        $tester->execute(['domain' => 'company.com', '--from' => '2026-08-01', '--to' => '2026-08-20']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('2026-08-19', $tester->getDisplay());
        self::assertStringContainsString('100', $tester->getDisplay());

        $tester = $this->tester(new DomainTrafficGetCommand());
        $tester->execute(['domain' => 'company.com', '--from' => 'not-a-date']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('YYYY-MM-DD', $tester->getDisplay());
    }

    public function testTrafficSetRecordsCountersAndValidates(): void
    {
        $tester = $this->tester(new DomainTrafficSetCommand());
        $tester->execute(['domain' => 'company.com', '--date' => '2026-08-19', '--smtp-in' => '5', '--smtp-out' => '6']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['smtp_in' => 5, 'smtp_out' => 6], $this->gateway->trafficSets['company.com']['counters']);

        $entries = $this->context->syncLogRepository()->recent(1);
        self::assertSame('traffic:set', $entries[0]['action']);
        self::assertStringContainsString('"date":"2026-08-19"', (string) $entries[0]['details']);

        $tester = $this->tester(new DomainTrafficSetCommand());
        $tester->execute(['domain' => 'company.com', '--date' => 'yesterday']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('YYYY-MM-DD', $tester->getDisplay());

        $tester = $this->tester(new DomainTrafficSetCommand());
        $tester->execute(['domain' => 'company.com', '--date' => '2026-08-19']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('at least one counter', $tester->getDisplay());
    }

    public function testDescriptorShowsProperties(): void
    {
        $this->gateway->descriptors['company.com'] = [
            ['name' => 'ftp_login', 'type' => 'string', 'default' => 'user', 'label' => 'FTP login'],
        ];

        $tester = $this->tester(new DomainDescriptorCommand());
        $tester->execute(['domain' => 'company.com']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ftp_login', $tester->getDisplay());
        self::assertStringContainsString('FTP login', $tester->getDisplay());
    }
}
