<?php

declare(strict_types=1);

namespace App\Tests\Command\Config;

use App\Application\PleadApplication;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 */
#[CoversNothing]
final class ConfigCommandsTest extends TestCase
{
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        TestEnv::isolate(sys_get_temp_dir().'/plead-app-test-'.bin2hex(random_bytes(4)));
        putenv('PLEAD_SERVER');
        putenv('PLEAD_PLESK_SECRET_KEY');

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        TestEnv::reset();
        putenv('PLEAD_SERVER');
        putenv('PLEAD_PLESK_SECRET_KEY');
    }

    public function testSetGetListRoundtrip(): void
    {
        self::assertSame(0, $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.company.com']));

        self::assertSame(0, $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 's3cret']));

        self::assertSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'servers.0.host']));
        self::assertStringContainsString('mail.company.com', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['command' => 'config:list']));
        self::assertStringContainsString('mail.company.com', $this->tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $this->tester->getDisplay());
    }

    public function testListFailsGracefullyWithoutConfig(): void
    {
        self::assertNotSame(0, $this->tester->run(['command' => 'config:list']));
        self::assertStringContainsString('No complete configuration found', $this->tester->getDisplay());
    }

    public function testRejectsUnknownKey(): void
    {
        self::assertNotSame(0, $this->tester->run(['command' => 'config:set', 'key' => 'plesk.wrong', 'value' => 'x']));
        self::assertStringContainsString('is not writable', $this->tester->getDisplay());
    }

    public function testWatchIntervalStoredAsInteger(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.company.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 'k']);
        self::assertSame(0, $this->tester->run(['command' => 'config:set', 'key' => 'watch.interval', 'value' => '90']));

        // The stored value must survive config validation (int, not string).
        self::assertSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'watch.interval']));
        self::assertStringContainsString('90', $this->tester->getDisplay());
    }

    public function testWatchIntervalRejectsNonInteger(): void
    {
        self::assertNotSame(0, $this->tester->run(['command' => 'config:set', 'key' => 'watch.interval', 'value' => 'abc']));
        self::assertStringContainsString('positive integer', $this->tester->getDisplay());
    }

    public function testGetUnknownKeyFails(): void
    {
        self::assertNotSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'nope.missing']));
    }

    public function testViewShowsMergedConfigurationWithEnvOverride(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'file.example.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 'file-secret']);
        $this->tester->run(['command' => 'config:set', 'key' => 'log_level', 'value' => 'debug']);

        putenv('PLEAD_PLESK_SECRET_KEY=env-secret');

        try {
            $status = $this->tester->run(['command' => 'config:view']);
        } finally {
            putenv('PLEAD_PLESK_SECRET_KEY');
        }

        self::assertSame(0, $status);
        self::assertStringContainsString('env-secret', $this->tester->getDisplay());
        self::assertStringContainsString('debug', $this->tester->getDisplay());
    }

    public function testViewFailsGracefullyWithoutConfig(): void
    {
        self::assertNotSame(0, $this->tester->run(['command' => 'config:view']));
        self::assertStringContainsString('No complete configuration found', $this->tester->getDisplay());
    }
}
