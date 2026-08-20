<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Util\Spinner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared watch-loop behaviour: a reconcile pass, a spinner while waiting for
 * the next pass, and graceful shutdown on SIGINT/SIGTERM.
 */
abstract class AbstractWatchCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between reconcile passes', '60')
            ->addOption('full', null, InputOption::VALUE_NONE, $this->fullOptionDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int) $input->getOption('interval'));
        $full = (bool) $input->getOption('full');

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $shouldStop = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, static function () use (&$shouldStop): void {
                $shouldStop = true;
            });
            pcntl_signal(SIGINT, static function () use (&$shouldStop): void {
                $shouldStop = true;
            });
        }

        $output->writeln(sprintf(
            'Watching %s (interval %ds%s).',
            $this->watchName(),
            $interval,
            $full ? ', full sweep' : '',
        ));

        // The spinner only makes sense on an interactive terminal; piped or
        // CI output falls back to plain log lines (finish() handles that).
        $spinner = new Spinner($output, defined('STDOUT') && stream_isatty(STDOUT) && 'Windows' !== PHP_OS_FAMILY);

        while (!$shouldStop) {
            $spinner->start();

            $count = $this->runPass($full);

            $spinner->finish(sprintf('[%s] %s', date('Y-m-d H:i:s'), $this->passMessage($count)));

            for ($elapsed = 0; $elapsed < $interval && !$shouldStop; $elapsed += 1) {
                usleep(1_000_000);
                $spinner->tick(sprintf('%ds/%ds', $elapsed + 1, $interval));
            }
        }

        $spinner->stop();
        $output->writeln('Stopped.');

        return self::SUCCESS;
    }

    abstract protected function watchName(): string;

    abstract protected function runPass(bool $full): int;

    abstract protected function passMessage(int $count): string;

    abstract protected function fullOptionDescription(): string;
}
