<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Indeterminate terminal spinner (technique from
 * https://blog.joe.codes/creating-a-loading-spinner-in-the-terminal-using-php):
 * a single line is overwritten in place via CR + erase-line, with the cursor
 * hidden while spinning.
 *
 * When $active is false (piped output, CI, Windows), every call degrades to
 * plain logging: finish() just writes its message on its own line.
 */
final class Spinner
{
    private const FRAMES = ['⢿', '⣻', '⣽', '⣾', '⣷', '⣯', '⣟', '⡿'];

    private int $frame = 0;
    private bool $running = false;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $active,
    ) {
    }

    /** Begin spinning on the current line. */
    public function start(): void
    {
        if (!$this->active) {
            return;
        }

        $this->running = true;
        $this->output->write("\e[?25l");
        $this->render('');
    }

    /** Advance one frame, optionally with an updating detail suffix. */
    public function tick(string $detail = ''): void
    {
        if (!$this->active) {
            return;
        }

        if (!$this->running) {
            $this->start();

            return;
        }

        $this->render($detail);
    }

    /**
     * Replace the spinner line with a final, permanent message line.
     */
    public function finish(string $message): void
    {
        if (!$this->active) {
            $this->output->writeln($message);

            return;
        }

        $this->running = false;
        $this->overwrite($message);
        $this->output->writeln('');
        $this->output->write("\e[?25h");
    }

    /** Clear the spinner line and restore the cursor (loop shutdown). */
    public function stop(): void
    {
        if (!$this->active) {
            return;
        }

        $this->running = false;
        $this->overwrite('');
        $this->output->write("\e[?25h");
    }

    private function render(string $detail): void
    {
        $frame = self::FRAMES[$this->frame];
        $this->frame = ($this->frame + 1) % count(self::FRAMES);
        $this->overwrite($frame . ('' !== $detail ? ' ' . $detail : ''));
    }

    private function overwrite(string $message): void
    {
        // CR moves to the start of the line, ESC [ 2K erases it.
        $this->output->write("\x0D\x1B[2K" . $message);
    }
}
