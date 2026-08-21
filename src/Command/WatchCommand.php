<?php

declare(strict_types=1);

namespace App\Command;

use App\Util\WatchLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'watch', description: 'Continuously converge mail state and rule-driven groups toward the desired state')]
final class WatchCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between reconcile passes', '60')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Re-verify everything against the server to catch drift')
            ->addOption('detached', 'd', InputOption::VALUE_NONE, 'Run in the background; output goes to the log file')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('detached') && false === getenv('PLEAD_DETACHED')) {
            return $this->detach($input, $output);
        }

        $interval = max(1, (int) $input->getOption('interval'));
        $full = (bool) $input->getOption('full');

        return new WatchLoop($output)->run(
            'mail state and rule-driven groups',
            $interval,
            $full,
            fn (bool $full): int => $this->runPass($full),
            static fn (int $count): string => sprintf('changed %d item%s', $count, 1 === $count ? '' : 's'),
        );
    }

    /** @return int number of changed items this pass */
    private function runPass(bool $full): int
    {
        $context = $this->context();
        $changed = 0;

        // Rule-driven groups: recompute the desired recipients from the live
        // address list (or the configured static list) and record new intents
        // when the computed set diverged from the local state.
        $entries = $context->config()['mail']['group'] ?? [];
        if ([] !== $entries) {
            $changed += $context->groupRuleEngine()->applyAll($entries);
        }

        // Pending intents (and, with --full, the whole managed state) pushed
        // to Plesk - including the rule intents recorded above.
        $changed += $context->reconciler()->reconcileAll($full);
        $changed += $context->reconcilerMail()->reconcileAll($full);
        $changed += $context->reconcilerAlias()->reconcileAll($full);

        return $changed;
    }

    private function detach(InputInterface $input, OutputInterface $output): int
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            $output->writeln('<error>--detached requires the pcntl extension (the container image ships it).</error>');

            return self::FAILURE;
        }

        $context = $this->context();
        $logFile = $context->paths->logFile();

        $pid = pcntl_fork();
        if (-1 === $pid) {
            $output->writeln('<error>Unable to fork the detached watcher.</error>');

            return self::FAILURE;
        }

        if (0 === $pid) {
            // Child: run the watcher with stdio pointed at the log file. The
            // two fopen calls pick up the freed fds 1 and 2 (STDOUT/STDERR).
            if (!is_dir(dirname($logFile)) && !mkdir(dirname($logFile), 0o775, true) && !is_dir(dirname($logFile))) {
                exit(1);
            }
            if (defined('STDOUT')) {
                fclose(STDOUT);
            }
            if (defined('STDERR')) {
                fclose(STDERR);
            }
            if (false === fopen($logFile, 'a') || false === fopen($logFile, 'a')) {
                exit(1);
            }

            putenv('PLEAD_DETACHED=1');
            $argv = $this->childArgv($input);
            @pcntl_exec(PHP_BINARY, $argv);

            exit(127);
        }

        $pidFile = $context->paths->dataDir().'/plead-'.$context->serverHost().'.watch.pid';
        if (!is_dir(dirname($pidFile)) && !mkdir(dirname($pidFile), 0o775, true) && !is_dir(dirname($pidFile))) {
            $output->writeln(sprintf('<error>Unable to create data directory: %s</error>', dirname($pidFile)));

            return self::FAILURE;
        }
        file_put_contents($pidFile, (string) $pid);

        $output->writeln(sprintf('Watcher detached (pid %d).', $pid));
        $output->writeln(sprintf('Log: %s', $logFile));
        $output->writeln(sprintf('Stop with: kill %d', $pid));

        return self::SUCCESS;
    }

    /** @return list<string> argv for the re-executed watcher child */
    private function childArgv(InputInterface $input): array
    {
        $script = dirname(__DIR__, 2).'/bin/plead';

        $argv = [$script, 'watch'];
        foreach (['interval', 'full', 'server', 'config', 'log-level', 'dry-run'] as $option) {
            $value = $input->getOption($option);
            if (null === $value || false === $value || '' === $value) {
                continue;
            }
            $argv[] = '--'.$option;
            if (true !== $value) {
                $argv[] = (string) $value;
            }
        }

        return $argv;
    }
}
