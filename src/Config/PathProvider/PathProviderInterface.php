<?php

declare(strict_types=1);

namespace App\Config\PathProvider;

interface PathProviderInterface
{
    public function configHome(): string;

    /** @return string[] precedence order, most-specific first */
    public function configDirs(): array;

    /** @return string[] configDirs x [json, yaml] */
    public function configPaths(): array;

    public function dataHome(): string;

    public function dataDir(): string;

    public function cacheHome(): string;

    public function logFile(): string;
}
