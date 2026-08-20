<?php

declare(strict_types=1);

namespace App\Config\PathProvider;

final class DarwinPathProvider implements PathProviderInterface
{
    private const APP_SUPPORT = '/Library/Application Support/plead';

    public function configHome(): string
    {
        return $this->env('XDG_CONFIG_HOME', $this->home().self::APP_SUPPORT);
    }

    public function dataHome(): string
    {
        return $this->env('PLEAD_DATA_DIR', $this->env('XDG_DATA_HOME', $this->home().self::APP_SUPPORT));
    }

    public function dataDir(): string
    {
        return $this->dataHome();
    }

    public function cacheHome(): string
    {
        return $this->env('XDG_CACHE_HOME', $this->home().'/Library/Caches/plead');
    }

    public function configDirs(): array
    {
        return [$this->configHome(), '/Library'.self::APP_SUPPORT];
    }

    public function configPaths(): array
    {
        $paths = [];
        foreach ($this->configDirs() as $dir) {
            $paths[] = $dir.'/plead.yaml';
            $paths[] = $dir.'/plead.json';
        }

        return $paths;
    }

    public function logFile(): string
    {
        return $this->dataDir().'/plead.log';
    }

    private function home(): string
    {
        return getenv('HOME') ?: sys_get_temp_dir();
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
