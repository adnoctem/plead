<?php

declare(strict_types=1);

namespace App\Tests\Command\Config;

use App\Application\PleadApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

final class ConfigEditCommandTest extends TestCase
{
    private string $configDir;
    private string $editorScript;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/plead-edit-test-' . bin2hex(random_bytes(4));
        $this->configDir = $base . '/config';
        mkdir($this->configDir, 0777, true);
        putenv('HOME=' . $base);
        putenv('XDG_CONFIG_HOME=' . $this->configDir);
        putenv('XDG_DATA_HOME=' . $base . '/data');

        $this->editorScript = $base . '/editor.sh';
        file_put_contents($this->editorScript, "#!/bin/sh\nprintf 'log_level: debug\\n' >> \"\$1\"\n");
        chmod($this->editorScript, 0755);
        putenv('EDITOR=' . $this->editorScript);

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');
        putenv('XDG_DATA_HOME');
        putenv('EDITOR');
    }

    public function testEditCreatesMissingFileAndValidates(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'plesk.host', 'value' => 'mail.example.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'plesk.secret_key', 'value' => 'k']);
        $this->tester->run(['command' => 'config:edit']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Configuration is valid', $this->tester->getDisplay());
        self::assertStringContainsString('log_level: debug', (string) file_get_contents($this->configDir . '/plead/plead.yaml'));
    }

    public function testEditReportsInvalidConfig(): void
    {
        $this->tester->run(['command' => 'config:edit']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Configuration is invalid', $this->tester->getDisplay());
    }

    public function testEditorFailureIsReported(): void
    {
        $failing = dirname($this->editorScript) . '/failing-editor.sh';
        file_put_contents($failing, "#!/bin/sh\nexit 3\n");
        chmod($failing, 0755);
        putenv('EDITOR=' . $failing);

        $this->tester->run(['command' => 'config:edit']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Editor exited with status 3', $this->tester->getDisplay());
    }
}
