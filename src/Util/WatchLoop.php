<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The reconcile-loop: a pass, a spinner while waiting for the next one, and
 * graceful shutdown on SIGINT/SIGTERM. TTY-gated, so piped and detached
 * output falls back to plain log lines.
 */
final class WatchLoop
{
    public function __construct(private readonly OutputInterface $output) {}

    /**
     * @param callable(bool $full): int    $runPass
     * @param callable(int $count): string $passMessage
     */
    public function run(
        string $watchName,
        int $interval,
        bool $full,
        callable $runPass,
        callable $passMessage,
    ): int {
        $interval = max(1, $interval);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        // The stop flag is a shared object so the pcntl signal handlers can
        // mutate it; a plain local would look constant to static analysis.
        $stop = new StopFlag();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, static function () use ($stop): void {
                $stop->request();
            });
            pcntl_signal(SIGINT, static function () use ($stop): void {
                $stop->request();
            });
        }

        $this->output->writeln(sprintf(
            'Watching %s (interval %ds%s).',
            $watchName,
            $interval,
            $full ? ', full sweep' : '',
        ));

        // The spinner only makes sense on an interactive terminal; piped or
        // CI output falls back to plain log lines (finish() handles that).
        $spinner = new Spinner($this->output, defined('STDOUT') && stream_isatty(STDOUT) && 'Windows' !== PHP_OS_FAMILY);

        while (!$stop->isRequested()) {
            $spinner->start();

            $count = $runPass($full);

            $spinner->finish(sprintf('[%s] %s', date('Y-m-d H:i:s'), $passMessage($count)));

            // PHPStan assumes isRequested() is loop-invariant, but the pcntl
            // signal handler flips it mid-iteration - that is the whole point.
            // @phpstan-ignore-next-line
            for ($elapsed = 0; $elapsed < $interval && !$stop->isRequested(); ++$elapsed) {
                usleep(1_000_000);
                $spinner->tick(sprintf('%ds/%ds', $elapsed + 1, $interval));
            }
        }

        $spinner->stop();
        $this->output->writeln('Stopped.');

        return 0;
    }
}
