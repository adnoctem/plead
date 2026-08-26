<?php

declare(strict_types=1);

namespace App\Tests\Command\Db;

use App\Application\PleadApplication;
use App\Config\PathProvider\PathProviderInterface;
use App\Tests\Support\FakeBin;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 */
#[CoversNothing]
final class DbCommandsTest extends TestCase
{
    private PathProviderInterface $paths;
    private string $fakeBin;
    private string $sqlite3Fixture;
    private string $originalPath;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/plead-db-test-'.bin2hex(random_bytes(4));
        $this->paths = TestEnv::paths($base);
        $this->fakeBin = $base.'/bin';
        mkdir($this->fakeBin, 0o777, true);
        $this->sqlite3Fixture = dirname(__DIR__, 2).'/Support/fake-bin/sqlite3.php';
        // putenv('PATH') without a value would *remove* PATH from the process
        // environment and break every later child process that resolves php
        // via `env php`; capture it so tearDown can restore it instead.
        $this->originalPath = (string) getenv('PATH');

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        TestEnv::reset();
        putenv('PATH='.$this->originalPath);
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
        $this->installFakeSqlite3();
        $this->prependFakeBinToPath();

        $this->tester->run(['command' => 'db:query']);
        $this->tester->run(['command' => 'db:path']);

        self::assertStringContainsString('(exists)', $this->tester->getDisplay());
        self::assertTrue(is_file($this->databasePath()));
    }

    public function testQueryOpensSqliteShell(): void
    {
        $this->installFakeSqlite3();
        $this->prependFakeBinToPath();

        $this->tester->run(['command' => 'db:query']);

        self::assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        self::assertStringContainsString('Opening '.$this->databasePath().' with sqlite3', $this->tester->getDisplay());
        // The fake stamps the database file with the path it was given, so
        // the file content proves the shell opened exactly this database.
        self::assertStringContainsString($this->databasePath(), (string) file_get_contents($this->databasePath()));
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
        return $this->paths->dataDir().'/plead.sqlite';
    }

    private function installFakeSqlite3(): void
    {
        FakeBin::install($this->sqlite3Fixture, $this->fakeBin.'/sqlite3');
    }

    private function prependFakeBinToPath(): void
    {
        putenv('PATH='.$this->fakeBin.PATH_SEPARATOR.(string) getenv('PATH'));
    }
}
