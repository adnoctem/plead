<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Util\Spinner;
use App\Util\StopFlag;
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
            ->addOption('full', null, InputOption::VALUE_NONE, $this->fullOptionDescription())
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int) $input->getOption('interval'));
        $full = (bool) $input->getOption('full');

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

        $output->writeln(sprintf(
            'Watching %s (interval %ds%s).',
            $this->watchName(),
            $interval,
            $full ? ', full sweep' : '',
        ));

        // The spinner only makes sense on an interactive terminal; piped or
        // CI output falls back to plain log lines (finish() handles that).
        $spinner = new Spinner($output, defined('STDOUT') && stream_isatty(STDOUT) && 'Windows' !== PHP_OS_FAMILY);

        while (!$stop->isRequested()) {
            $spinner->start();

            $count = $this->runPass($full);

            $spinner->finish(sprintf('[%s] %s', date('Y-m-d H:i:s'), $this->passMessage($count)));

            // PHPStan assumes isRequested() is loop-invariant, but the pcntl
            // signal handler flips it mid-iteration - that is the whole point.
            // @phpstan-ignore-next-line
            for ($elapsed = 0; $elapsed < $interval && !$stop->isRequested(); ++$elapsed) {
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
