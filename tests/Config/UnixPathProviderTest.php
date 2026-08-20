<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\PathProvider\UnixPathProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class UnixPathProviderTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/plead-home-test';
        putenv('HOME='.$this->home);
        putenv('XDG_CONFIG_HOME');
        putenv('XDG_DATA_HOME');
        putenv('XDG_CACHE_HOME');
        putenv('PLEAD_DATA_DIR');
    }

    protected function tearDown(): void
    {
        putenv('HOME');
        putenv('XDG_CONFIG_HOME');
        putenv('XDG_DATA_HOME');
        putenv('XDG_CACHE_HOME');
        putenv('PLEAD_DATA_DIR');
    }

    public function testDefaultsFollowXdgConventions(): void
    {
        $provider = new UnixPathProvider();

        self::assertSame($this->home.'/.config', $provider->configHome());
        self::assertSame($this->home.'/.local/share', $provider->dataHome());
        self::assertSame($this->home.'/.cache', $provider->cacheHome());
        self::assertSame($this->home.'/.local/share/plead', $provider->dataDir());
    }

    public function testXdgOverridesAreHonored(): void
    {
        putenv('XDG_CONFIG_HOME=/tmp/xdg-config');
        putenv('XDG_DATA_HOME=/tmp/xdg-data');

        $provider = new UnixPathProvider();

        self::assertSame('/tmp/xdg-config', $provider->configHome());
        self::assertSame('/tmp/xdg-data', $provider->dataHome());
    }

    public function testConfigDirsMostSpecificFirst(): void
    {
        $provider = new UnixPathProvider();

        self::assertSame([$this->home.'/.config/plead', '/etc/plead'], $provider->configDirs());
    }

    public function testConfigPathsAlternateYamlThenJsonPerDir(): void
    {
        $provider = new UnixPathProvider();

        self::assertSame([
            $this->home.'/.config/plead/plead.yaml',
            $this->home.'/.config/plead/plead.json',
            '/etc/plead/plead.yaml',
            '/etc/plead/plead.json',
        ], $provider->configPaths());
    }

    public function testLogFileLivesUnderDataDir(): void
    {
        $provider = new UnixPathProvider();

        self::assertSame($this->home.'/.local/share/plead/plead.log', $provider->logFile());
    }

    public function testPleadDataDirOverridesDataHome(): void
    {
        putenv('PLEAD_DATA_DIR=/data');

        $provider = new UnixPathProvider();

        self::assertSame('/data', $provider->dataHome());
        self::assertSame('/data/plead', $provider->dataDir());
        self::assertSame('/data/plead/plead.log', $provider->logFile());
    }
}
