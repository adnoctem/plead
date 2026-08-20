<?php

declare(strict_types=1);

namespace App\Config\PathProvider;

final class UnixPathProvider implements PathProviderInterface
{
    public function configHome(): string
    {
        return $this->env('XDG_CONFIG_HOME', $this->home().'/.config');
    }

    public function dataHome(): string
    {
        return $this->env('PLEAD_DATA_DIR', $this->env('XDG_DATA_HOME', $this->home().'/.local/share'));
    }

    public function dataDir(): string
    {
        return $this->dataHome().'/plead';
    }

    public function cacheHome(): string
    {
        return $this->env('XDG_CACHE_HOME', $this->home().'/.cache');
    }

    public function configDirs(): array
    {
        return [$this->configHome().'/plead', '/etc/plead'];
    }

    public function configPaths(): array
    {
        return $this->paths($this->configDirs());
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

    /**
     * @param string[] $dirs
     *
     * @return string[]
     */
    private function paths(array $dirs): array
    {
        $paths = [];
        foreach ($dirs as $dir) {
            $paths[] = $dir.'/plead.yaml';
            $paths[] = $dir.'/plead.json';
        }

        return $paths;
    }
}
