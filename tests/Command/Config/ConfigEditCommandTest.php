<?php

declare(strict_types=1);

namespace App\Tests\Command\Config;

use App\Application\PleadApplication;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 */
#[CoversNothing]
final class ConfigEditCommandTest extends TestCase
{
    private PathProviderInterface $paths;
    private string $target;
    private string $editorScript;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/plead-edit-test-'.bin2hex(random_bytes(4));
        $this->paths = TestEnv::paths($base);
        $this->target = $this->paths->configPaths()[0];

        // A PHP test double instead of a shell script: "php" resolves on
        // PATH on every platform, no executable shim required.
        $this->editorScript = dirname(__DIR__, 2).'/Support/fake-bin/editor.php';
        putenv('EDITOR=php '.$this->editorScript);

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        TestEnv::reset();
        putenv('EDITOR');
    }

    public function testEditCreatesMissingFileAndValidates(): void
    {
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.host', 'value' => 'mail.example.com']);
        $this->tester->run(['command' => 'config:set', 'key' => 'servers.0.secret_key', 'value' => 'k']);
        $this->tester->run(['command' => 'config:edit']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Configuration is valid', $this->tester->getDisplay());
        self::assertStringContainsString('log_level: debug', (string) file_get_contents($this->target));
    }

    public function testEditReportsInvalidConfig(): void
    {
        $this->tester->run(['command' => 'config:edit']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Configuration is invalid', $this->tester->getDisplay());
    }

    public function testEditorFailureIsReported(): void
    {
        $failing = dirname($this->editorScript).'/failing-editor.php';
        putenv('EDITOR=php '.$failing);

        $this->tester->run(['command' => 'config:edit']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Editor exited with status 3', $this->tester->getDisplay());
    }

    public function testWarnsAboutStaleSwapFile(): void
    {
        $directory = dirname($this->target);
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/.'.basename($this->target).'.swp', 'stale');

        $this->tester->run(['command' => 'config:edit']);

        self::assertStringContainsString('Stale swap file found at', $this->tester->getDisplay());
    }

    public function testMissingEditorIsReported(): void
    {
        putenv('EDITOR='.dirname($this->editorScript).'/does-not-exist');

        $this->tester->run(['command' => 'config:edit']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('was not found on your PATH', $this->tester->getDisplay());
    }
}
