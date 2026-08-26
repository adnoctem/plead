<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Installs the committed test doubles from tests/Support/fake-bin as
 * executable "binaries".
 *
 * On Unix the scripts carry a shebang and are copied verbatim; on Windows,
 * where no shebang exists, a .cmd wrapper that forwards to the PHP binary
 * is generated next to the requested target path.
 */
final class FakeBin
{
    public static function install(string $fixture, string $target): string
    {
        if ('Windows' !== PHP_OS_FAMILY) {
            copy($fixture, $target);
            chmod($target, 0o755);

            return $target;
        }

        $wrapper = $target.'.cmd';
        file_put_contents(
            $wrapper,
            '@"'.PHP_BINARY.'" "'.str_replace('\\', '/', $fixture).'" %*',
        );

        return $wrapper;
    }
}
