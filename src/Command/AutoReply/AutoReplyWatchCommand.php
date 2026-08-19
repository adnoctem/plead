<?php

declare(strict_types=1);

namespace App\Command\AutoReply;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auto-reply:watch', description: 'Continuously apply scheduled auto-replies as their start time is reached')]
final class AutoReplyWatchCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between reconcile passes', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int) $input->getOption('interval'));
        $reconciler = $this->context()->reconciler();

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

        $output->writeln(sprintf('Watching for auto-replies (interval %ds).', $interval));

        while (!$shouldStop) {
            $applied = $reconciler->reconcileAll();
            $output->writeln(sprintf('[%s] applied %d auto-repl%s', date('Y-m-d H:i:s'), $applied, 1 === $applied ? 'y' : 'ies'));

            for ($elapsed = 0; $elapsed < $interval && !$shouldStop; $elapsed += 1) {
                usleep(1_000_000);
            }
        }

        $output->writeln('Stopped.');

        return self::SUCCESS;
    }
}
