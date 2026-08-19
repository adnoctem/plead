<?php

declare(strict_types=1);

namespace App\Config\PathProvider;

final class WindowsPathProvider implements PathProviderInterface
{
    public function configHome(): string
    {
        return $this->local() . '/plead';
    }

    public function dataHome(): string
    {
        return $this->env('PLEAD_DATA_DIR', $this->local() . '/plead');
    }

    public function dataDir(): string
    {
        return $this->dataHome();
    }

    public function cacheHome(): string
    {
        return $this->local() . '/plead/cache';
    }

    public function configDirs(): array
    {
        $dirs = [$this->configHome()];
        if (false !== ($roaming = getenv('APPDATA')) && '' !== $roaming) {
            $dirs[] = $roaming . '/plead';
        }
        if (false !== ($programData = getenv('ProgramData')) && '' !== $programData) {
            $dirs[] = $programData . '/plead';
        }

        return $dirs;
    }

    public function configPaths(): array
    {
        $paths = [];
        foreach ($this->configDirs() as $dir) {
            $paths[] = $dir . '/plead.yaml';
            $paths[] = $dir . '/plead.json';
        }

        return $paths;
    }

    public function logFile(): string
    {
        return $this->dataDir() . '/plead.log';
    }

    private function local(): string
    {
        return getenv('LOCALAPPDATA') ?: getenv('APPDATA') ?: sys_get_temp_dir();
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
