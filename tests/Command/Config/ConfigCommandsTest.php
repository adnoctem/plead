<?php

declare(strict_types=1);

namespace App\Tests\Command\Config;

use App\Application\PleadApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 */
#[CoversNothing]
final class ConfigCommandsTest extends TestCase
{
    private string $configDir;
    private string $dataDir;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/plead-app-test-'.bin2hex(random_bytes(4));
        $this->configDir = $base.'/config';
        $this->dataDir = $base.'/data';
        putenv('HOME='.$base);
        putenv('XDG_CONFIG_HOME='.$this->configDir);
        putenv('XDG_DATA_HOME='.$this->dataDir);
        putenv('PLEAD_SERVER');
        putenv('PLEAD_PLESK_SECRET_KEY');

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');
        putenv('XDG_DATA_HOME');
        putenv('PLEAD_SERVER');
        putenv('PLEAD_PLESK_SECRET_KEY');
    }

    public function testSetGetListRoundtrip(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.company.com']);
        self::assertSame(0, $this->tester->getStatusCode());

        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 's3cret']);
        self::assertSame(0, $this->tester->getStatusCode());

        $this->tester->run(['command' => 'config:get', 'key' => 'servers.0.host']);
        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('mail.company.com', $this->tester->getDisplay());

        $this->tester->run(['command' => 'config:list']);
        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('mail.company.com', $this->tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $this->tester->getDisplay());
    }

    public function testListFailsGracefullyWithoutConfig(): void
    {
        $this->tester->run(['command' => 'config:list']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('No complete configuration found', $this->tester->getDisplay());
    }

    public function testRejectsUnknownKey(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'plesk.wrong', 'value' => 'x']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('is not writable', $this->tester->getDisplay());
    }

    public function testGetUnknownKeyFails(): void
    {
        $this->tester->run(['command' => 'config:get', 'key' => 'nope.missing']);

        self::assertNotSame(0, $this->tester->getStatusCode());
    }

    public function testViewShowsMergedConfigurationWithEnvOverride(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'file.example.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 'file-secret']);
        $this->tester->run(['command' => 'config:set', 'key' => 'log_level', 'value' => 'debug']);

        putenv('PLEAD_PLESK_SECRET_KEY=env-secret');

        try {
            $this->tester->run(['command' => 'config:view']);
        } finally {
            putenv('PLEAD_PLESK_SECRET_KEY');
        }

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('env-secret', $this->tester->getDisplay());
        self::assertStringContainsString('debug', $this->tester->getDisplay());
    }

    public function testViewFailsGracefullyWithoutConfig(): void
    {
        $this->tester->run(['command' => 'config:view']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('No complete configuration found', $this->tester->getDisplay());
    }
}
