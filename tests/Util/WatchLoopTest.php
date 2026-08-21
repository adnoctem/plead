<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\WatchLoop;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversNothing]
final class WatchLoopTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl is required for the watch loop.');
        }
    }

    public function testLoopRunsPassesAndStopsOnSigterm(): void
    {
        $output = new BufferedOutput();
        $calls = 0;

        $code = new WatchLoop($output)->run(
            'things',
            60,
            false,
            function (bool $full) use (&$calls): int {
                ++$calls;
                // The loop has installed its signal handlers by now; the stop
                // flag flips and the loop exits after this pass.
                posix_kill(getmypid(), SIGTERM);

                return 1;
            },
            static fn (int $count): string => sprintf('changed %d item%s', $count, 1 === $count ? '' : 's'),
        );

        self::assertSame(0, $code);
        self::assertSame(1, $calls);
        self::assertStringContainsString('Watching things (interval 60s).', $output->fetch());
    }

    public function testLoopPassesFullFlag(): void
    {
        $output = new BufferedOutput();
        $seenFull = null;

        new WatchLoop($output)->run(
            'things',
            60,
            true,
            function (bool $full) use (&$seenFull): int {
                $seenFull = $full;
                posix_kill(getmypid(), SIGTERM);

                return 0;
            },
            static fn (int $count): string => (string) $count,
        );

        self::assertTrue($seenFull);
        self::assertStringContainsString('full sweep', $output->fetch());
    }
}
