<?php

declare(strict_types=1);

namespace App\Command;

use App\Util\WatchLoop;
use Phar;
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
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between reconcile passes (default: watch.interval from the config)')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Re-verify everything against the server to catch drift')
            ->addOption('detached', 'd', InputOption::VALUE_NONE, 'Run in the background; output goes to the log file')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('detached') && false === getenv('PLEAD_DETACHED')) {
            return $this->detach($input, $output);
        }

        $intervalOption = $input->getOption('interval');
        $interval = null !== $intervalOption
            ? (int) $intervalOption
            : (int) $this->context()->config()['watch']['interval'];
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

        // Inbox: absence files dropped by external tools (directory scripts,
        // HR exports, hand-written JSON) become auto-reply + forwarding
        // intents. Disabled unless watch.inbox.path is configured.
        $changed += $context->inboxProcessor()?->process() ?? 0;

        // Inbox expiry: Plesk disables the reply itself via end_date, but a
        // deputy forwarding has no end date and must be removed explicitly.
        $changed += $context->inboxExpiry()->expire();

        // Config-defined auto-replies: re-record intents when the rendered
        // message or dates diverged from the local state.
        $autoresponders = $context->config()['mail']['autoresponder'] ?? [];
        if ([] !== $autoresponders) {
            $changed += $context->autoReplyRuleEngine()->applyAll($autoresponders);
        }

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
        if (!function_exists('proc_open')) {
            $output->writeln('<error>--detached requires the proc_open function.</error>');

            return self::FAILURE;
        }

        $context = $this->context();
        $logFile = $context->paths->logFile();

        if (!is_dir(dirname($logFile)) && !mkdir(dirname($logFile), 0o775, true) && !is_dir(dirname($logFile))) {
            $output->writeln(sprintf('<error>Unable to create data directory: %s</error>', dirname($logFile)));

            return self::FAILURE;
        }

        // Spawn the watcher with its stdio pointed at the log file. A plain
        // proc_open with file redirection performs the fd remapping reliably
        // on every PHP version - the old fclose(STDOUT)/fopen() reuse trick
        // no longer frees the stdio fds on PHP 8.5. No shell is involved, so
        // the argv stays explicit (same rule as the TUI launcher).
        $env = getenv();
        $env['PLEAD_DETACHED'] = '1';
        $null = 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null';

        $proc = @proc_open(
            [PHP_BINARY, ...$this->childArgv($input)],
            [
                0 => ['file', $null, 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
            null,
            $env,
        );

        if (false === $proc) {
            $output->writeln('<error>Unable to start the detached watcher.</error>');

            return self::FAILURE;
        }

        $status = proc_get_status($proc);
        $pid = (int) $status['pid'];

        // Deliberately no proc_close(): it would wait for the watcher to
        // exit. The watcher keeps running and is reparented on our exit.

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
        // Inside the compiled phar the re-exec target is the phar itself
        // (Phar::running()); the source tree re-executes bin/plead.
        $script = \Phar::running(false) ?: dirname(__DIR__, 2).'/bin/plead';

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
