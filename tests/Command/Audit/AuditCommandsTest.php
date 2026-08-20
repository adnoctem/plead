<?php

declare(strict_types=1);

namespace App\Tests\Command\Audit;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Audit\AuditExportCommand;
use App\Command\Audit\AuditTrailCommand;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AuditCommandsTest extends TestCase
{
    private string $base;
    private RuntimeContext $context;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/plead-audit-cmd-' . bin2hex(random_bytes(4));
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

        $this->context = new RuntimeContext($paths, null, false, null, 0, gateway: new RecordingGateway());

        // Seed a small audit trail.
        $syncLog = $this->context->syncLogRepository();
        $id = $syncLog->logPending('mail_group', 'group@company.com', 'add');
        $syncLog->resolve($id, 'ok');
        $id = $syncLog->logPending('mail_address', 'user@company.com', 'rename', [
            'from' => 'user@company.com',
            'to' => 'newuser@company.com',
        ]);
        $syncLog->resolve($id, 'error:mail does not exist');
        $syncLog->log('mail_alias', 'user@company.com', 'adopt', 'ok');
    }

    private function tester(AbstractPleadCommand $command): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($this->context);

        return $tester;
    }

    public function testTrailNonInteractivePrintsTable(): void
    {
        $tester = $this->tester(new AuditTrailCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('3 audit entries', $tester->getDisplay());
        self::assertStringContainsString('mail_group', $tester->getDisplay());
        self::assertStringContainsString('group@company.com', $tester->getDisplay());
        self::assertStringContainsString('error:mail does not exist', $tester->getDisplay());
    }

    public function testTrailFiltersByResourceAndResult(): void
    {
        $tester = $this->tester(new AuditTrailCommand());
        $tester->execute(['--resource' => 'mail_group']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 audit entry', $tester->getDisplay());
        self::assertStringNotContainsString('mail_address', $tester->getDisplay());

        $tester = $this->tester(new AuditTrailCommand());
        $tester->execute(['--result' => 'error']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('error:mail does not exist', $tester->getDisplay());
        self::assertStringNotContainsString('adopt', $tester->getDisplay());
    }

    public function testTrailLimitIsHonored(): void
    {
        $tester = $this->tester(new AuditTrailCommand());
        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 audit entry', $tester->getDisplay());
    }

    public function testTrailRejectsInvalidLimit(): void
    {
        $tester = $this->tester(new AuditTrailCommand());
        $tester->execute(['--limit' => '0']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('positive integer', $tester->getDisplay());
    }

    public function testTrailEmptyTrail(): void
    {
        $fresh = $this->base . '/empty';
        mkdir($fresh . '/config', 0777, true);
        mkdir($fresh . '/data', 0777, true);
        file_put_contents(
            $fresh . '/config/plead.yaml',
            "plesk:\n    host: fake.local\n    secret_key: test-key\n",
        );
        $emptyContext = new RuntimeContext(
            new class($fresh) implements PathProviderInterface {
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
            },
            null,
            false,
            null,
            0,
            gateway: new RecordingGateway(),
        );

        $command = new AuditTrailCommand();
        $command->setContext($emptyContext);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('The audit trail is empty.', $tester->getDisplay());
    }

    public function testExportJsonToFile(): void
    {
        $target = $this->base . '/export.json';

        $tester = $this->tester(new AuditExportCommand());
        $tester->execute(['-o' => $target]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('3 audit entries', $tester->getDisplay());
        self::assertStringContainsString($target, $tester->getDisplay());

        $decoded = json_decode((string) file_get_contents($target), true);
        self::assertCount(3, $decoded);
        self::assertSame('mail_group', $decoded[2]['resource_type']);
        self::assertSame('ok', $decoded[2]['result']);
        self::assertSame('error:mail does not exist', $decoded[1]['result']);

        // The rename entry carries the original and the new value.
        self::assertSame('newuser@company.com', $decoded[1]['details']['to']);
        self::assertSame('user@company.com', $decoded[1]['details']['from']);
    }

    public function testExportYamlToFile(): void
    {
        $target = $this->base . '/export.yaml';

        $tester = $this->tester(new AuditExportCommand());
        $tester->execute(['--format' => 'yaml', '-o' => $target]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('yaml', $tester->getDisplay());

        $content = (string) file_get_contents($target);
        self::assertStringContainsString('resource_type: mail_group', $content);
        self::assertStringContainsString('group@company.com', $content);
    }

    public function testExportDefaultsToDataDir(): void
    {
        $tester = $this->tester(new AuditExportCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString($this->base . '/data/audit-export-', $tester->getDisplay());
        self::assertSame(1, count(glob($this->base . '/data/audit-export-*.json') ?: []));
    }

    public function testExportRejectsUnknownFormat(): void
    {
        $tester = $this->tester(new AuditExportCommand());
        $tester->execute(['--format' => 'xml']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unknown format', $tester->getDisplay());
    }
}
