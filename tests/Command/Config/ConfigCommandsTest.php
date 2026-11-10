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
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('mail.company.com', $display);
        self::assertStringContainsString('selected_server: mail.company.com', $display);
        self::assertStringNotContainsString('s3cret', $display);
        self::assertStringNotContainsString("\nplesk:", $display);
        self::assertStringNotContainsString("\nserver:", $display);
    }

    public function testGetMasksSecretValues(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.company.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 's3cret']);

        self::assertSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'servers.0.secret_key']));
        self::assertStringContainsString('***', $this->tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'servers.0']));
        self::assertStringContainsString('***', $this->tester->getDisplay());
        self::assertStringNotContainsString('s3cret', $this->tester->getDisplay());
    }

    public function testSetNestedPathsAndTemplates(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.company.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 'key']);

        self::assertSame(0, $this->tester->run([
            'command' => 'config:set',
            'key' => 'watch.inbox.paths.root',
            'value' => '/srv/inbox',
        ]));
        self::assertSame(0, $this->tester->run([
            'command' => 'config:set',
            'key' => 'template.paths.auto_reply',
            'value' => 'resources/templates/custom.txt.twig',
        ]));

        self::assertSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'watch.inbox.paths.root']));
        self::assertStringContainsString('/srv/inbox', $this->tester->getDisplay());
        self::assertSame(0, $this->tester->run(['command' => 'config:get', 'key' => 'template.paths.auto_reply']));
        self::assertStringContainsString('resources/templates/custom.txt.twig', $this->tester->getDisplay());
    }

    public function testGetExposesSecretsWhenAsked(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.company.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 's3cret']);

        self::assertSame(0, $this->tester->run([
            'command' => 'config:get',
            'key' => 'servers.0.secret_key',
            '--expose-secrets' => true,
        ]));
        self::assertStringContainsString('s3cret', $this->tester->getDisplay());

        self::assertSame(0, $this->tester->run([
            'command' => 'config:get',
            'key' => 'servers.0',
            '--expose-secrets' => true,
        ]));
        self::assertStringContainsString('s3cret', $this->tester->getDisplay());
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
}
