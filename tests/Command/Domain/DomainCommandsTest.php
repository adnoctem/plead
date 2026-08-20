<?php

declare(strict_types=1);

namespace App\Tests\Command\Domain;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Domain\DomainGetCommand;
use App\Command\Domain\DomainListCommand;
use App\Command\Domain\DomainSetCommand;
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

    public function testSetRequiresDescription(): void
    {
        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Provide --description', $tester->getDisplay());
    }

    public function testSetStatusIsNotYetSupported(): void
    {
        $tester = $this->tester(new DomainSetCommand());
        $tester->execute(['domain' => 'company.com', '--status' => 'enabled']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('not supported yet', $tester->getDisplay());
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
}
