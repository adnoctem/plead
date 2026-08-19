<?php

declare(strict_types=1);

namespace App\Config\PathProvider;

final class PathProviderFactory
{
    public static function create(): PathProviderInterface
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => new DarwinPathProvider(),
            'Windows' => new WindowsPathProvider(),
            default => new UnixPathProvider(),
        };
    }
}
