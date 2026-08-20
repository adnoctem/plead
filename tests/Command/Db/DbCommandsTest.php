<?php

declare(strict_types=1);

namespace App\Tests\Command\Db;

use App\Application\PleadApplication;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 */
#[CoversNothing]
final class DbCommandsTest extends TestCase
{
    private string $configDir;
    private string $dataDir;
    private string $fakeBin;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/plead-db-test-'.bin2hex(random_bytes(4));
        $this->configDir = $base.'/config';
        $this->dataDir = $base.'/data';
        $this->fakeBin = $base.'/bin';
        mkdir($this->fakeBin, 0o777, true);

        putenv('HOME='.$base);
        putenv('XDG_CONFIG_HOME='.$this->configDir);
        putenv('XDG_DATA_HOME='.$this->dataDir);

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');
        putenv('XDG_DATA_HOME');
        putenv('PATH');
    }

    public function testPathShowsDatabaseLocation(): void
    {
        $this->tester->run(['command' => 'db:path']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('database: '.$this->databasePath(), $this->tester->getDisplay());
        self::assertStringNotContainsString('(exists)', $this->tester->getDisplay());
    }

    public function testPathShowsExistsAfterDatabaseWasCreated(): void
    {
        $this->installFakeSqlite3('exit 0');

        $this->tester->run(['command' => 'db:query']);
        $this->tester->run(['command' => 'db:path']);

        self::assertStringContainsString('(exists)', $this->tester->getDisplay());
        self::assertTrue(is_file($this->databasePath()));
    }

    public function testQueryOpensSqliteShell(): void
    {
        $marker = dirname($this->fakeBin).'/called.txt';
        $this->installFakeSqlite3(sprintf('echo "$1" > %s', escapeshellarg($marker)));
        putenv('PATH='.$this->fakeBin);

        $this->tester->run(['command' => 'db:query']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('Opening '.$this->databasePath().' with sqlite3', $this->tester->getDisplay());
        self::assertSame($this->databasePath(), trim((string) file_get_contents($marker)));
    }

    public function testQueryFailsWithoutSqlite3(): void
    {
        putenv('PATH='.$this->fakeBin);

        $this->tester->run(['command' => 'db:query']);

        self::assertNotSame(0, $this->tester->getStatusCode());
        self::assertStringContainsString('sqlite3 was not found', $this->tester->getDisplay());
    }

    private function databasePath(): string
    {
        return $this->dataDir.'/plead/plead.sqlite';
    }

    private function installFakeSqlite3(string $body): void
    {
        $script = $this->fakeBin.'/sqlite3';
        file_put_contents($script, "#!/bin/sh\n".$body."\n");
        chmod($script, 0o755);
    }
}
