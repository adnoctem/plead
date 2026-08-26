<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\PathProvider\PathProviderFactory;
use App\Config\PathProvider\PathProviderInterface;

/**
 * Per-platform test isolation.
 *
 * The path providers read different environment variables depending on the
 * platform (XDG_* on Unix/macOS, LOCALAPPDATA/APPDATA/PLEAD_DATA_DIR on
 * Windows), so tests that go through PathProviderFactory::create() must
 * redirect the variables the *current* platform actually reads.
 */
final class TestEnv
{
    private const VARIABLES = [
        'HOME',
        'XDG_CONFIG_HOME',
        'XDG_DATA_HOME',
        'XDG_CACHE_HOME',
        'LOCALAPPDATA',
        'APPDATA',
        'ProgramData',
        'PLEAD_DATA_DIR',
    ];

    /**
     * Redirect the current platform's config/data locations into $base and
     * return the path provider the application will use.
     */
    public static function paths(string $base): PathProviderInterface
    {
        self::isolate($base);

        return PathProviderFactory::create();
    }

    public static function isolate(string $base): void
    {
        $config = $base.'/config';
        $data = $base.'/data';

        if ('Windows' === PHP_OS_FAMILY) {
            putenv('LOCALAPPDATA='.$config);
            putenv('APPDATA='.$base.'/roaming');
            putenv('ProgramData='.$base.'/programdata');
            putenv('PLEAD_DATA_DIR='.$data);

            return;
        }

        putenv('HOME='.$base);
        putenv('XDG_CONFIG_HOME='.$config);
        putenv('XDG_DATA_HOME='.$data);
        putenv('XDG_CACHE_HOME='.$base.'/cache');
    }

    public static function reset(): void
    {
        foreach (self::VARIABLES as $name) {
            putenv($name);
        }
    }
}
