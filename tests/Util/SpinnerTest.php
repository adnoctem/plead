<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\Spinner;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @internal
 */
#[CoversNothing]
final class SpinnerTest extends TestCase
{
    public function testActiveSpinnerHidesCursorOverwritesAndFinishes(): void
    {
        $output = new BufferedOutput();
        $spinner = new Spinner($output, true);

        $spinner->start();
        $spinner->tick('5s/60s');
        $spinner->finish('[2026-08-20 12:00:00] changed 1 list');

        $display = $output->fetch();

        self::assertStringContainsString("\e[?25l", $display, 'cursor hidden while spinning');
        self::assertStringContainsString("\x0D\x1B[2K", $display, 'lines overwritten in place');
        self::assertStringContainsString('5s/60s', $display, 'tick detail rendered');
        self::assertStringContainsString('changed 1 list', $display, 'final message rendered');
        self::assertStringContainsString("\e[?25h", $display, 'cursor restored on finish');
    }

    public function testStopClearsLineAndRestoresCursor(): void
    {
        $output = new BufferedOutput();
        $spinner = new Spinner($output, true);

        $spinner->start();
        $spinner->stop();

        $display = $output->fetch();

        self::assertStringContainsString("\x0D\x1B[2K", $display);
        self::assertStringContainsString("\e[?25h", $display);
    }

    public function testInactiveSpinnerDegradesToPlainLogging(): void
    {
        $output = new BufferedOutput();
        $spinner = new Spinner($output, false);

        $spinner->start();
        $spinner->tick('x');
        $spinner->finish('plain line');

        $display = $output->fetch();

        self::assertSame("plain line\n", $display, 'no terminal control sequences when inactive');
    }

    public function testInactiveStopIsSilent(): void
    {
        $output = new BufferedOutput();
        $spinner = new Spinner($output, false);

        $spinner->start();
        $spinner->stop();

        self::assertSame('', $output->fetch());
    }
}
