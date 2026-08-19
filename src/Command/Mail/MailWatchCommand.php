<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:watch', description: 'Continuously converge mail group recipients toward the desired state')]
final class MailWatchCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between reconcile passes', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, (int) $input->getOption('interval'));
        $reconciler = $this->context()->reconcilerMail();

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

        $output->writeln(sprintf('Watching mail groups (interval %ds).', $interval));

        while (!$shouldStop) {
            $changed = $reconciler->reconcileAll();
            $output->writeln(sprintf('[%s] changed %d list%s', date('Y-m-d H:i:s'), $changed, 1 === $changed ? '' : 's'));

            for ($elapsed = 0; $elapsed < $interval && !$shouldStop; $elapsed += 1) {
                usleep(1_000_000);
            }
        }

        $output->writeln('Stopped.');

        return self::SUCCESS;
    }
}
