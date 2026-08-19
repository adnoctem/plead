<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\RuntimeContext;
use App\Config\PathProvider\PathProviderInterface;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

final class RuntimeContextTest extends TestCase
{
    private PathProviderInterface $paths;

    protected function setUp(): void
    {
        $this->paths = new class implements PathProviderInterface {
            public function configHome(): string
            {
                return sys_get_temp_dir() . '/config';
            }

            public function configDirs(): array
            {
                return [$this->configHome()];
            }

            public function configPaths(): array
            {
                return [$this->configHome() . '/plead.yaml'];
            }

            public function dataHome(): string
            {
                return sys_get_temp_dir() . '/data';
            }

            public function dataDir(): string
            {
                return sys_get_temp_dir() . '/data/plead';
            }

            public function cacheHome(): string
            {
                return sys_get_temp_dir() . '/cache';
            }

            public function logFile(): string
            {
                return sys_get_temp_dir() . '/data/plead/plead.log';
            }
        };
    }

    public function testDefaultLogLevelIsInfo(): void
    {
        $context = new RuntimeContext($this->paths, null, false, null, OutputInterface::VERBOSITY_NORMAL);

        self::assertSame(Level::Info, $context->logLevel());
    }

    public function testVerboseRaisesFileLevelToDebug(): void
    {
        $context = new RuntimeContext($this->paths, null, false, 'info', OutputInterface::VERBOSITY_VERBOSE);

        self::assertSame(Level::Debug, $context->logLevel());
    }

    public function testExplicitLogLevelOverridesDefault(): void
    {
        $context = new RuntimeContext($this->paths, null, false, 'error', OutputInterface::VERBOSITY_NORMAL);

        self::assertSame(Level::Error, $context->logLevel());
    }

    public function testInvalidLogLevelFallsBackToInfo(): void
    {
        $context = new RuntimeContext($this->paths, null, false, 'loud', OutputInterface::VERBOSITY_NORMAL);

        self::assertSame(Level::Info, $context->logLevel());
    }
}
