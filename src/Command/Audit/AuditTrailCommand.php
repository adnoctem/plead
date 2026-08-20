<?php

declare(strict_types=1);

namespace App\Command\Audit;

use App\Command\AbstractPleadCommand;
use App\Util\AuditTrailViewer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'audit:trail', description: 'Browse the audit trail (interactive TUI on a TTY; plain table otherwise)')]
final class AuditTrailCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('resource', null, InputOption::VALUE_REQUIRED, 'Only entries of this resource type, e.g. mail_group')
            ->addOption('result', null, InputOption::VALUE_REQUIRED, 'Only entries with this result, e.g. ok, pending, error')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of entries (default: 200)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = 200;
        if (null !== $input->getOption('limit')) {
            $limit = (int) $input->getOption('limit');
            if ($limit < 1) {
                $output->writeln('<error>--limit must be a positive integer.</error>');

                return self::FAILURE;
            }
        }

        $entries = $this->context()->syncLogRepository()->all(
            $input->getOption('resource') ? (string) $input->getOption('resource') : null,
            $input->getOption('result') ? (string) $input->getOption('result') : null,
            $limit,
        );

        // The TUI needs a real terminal on both ends; a piped or buffered
        // output degrades to a plain table (and tests never block).
        $interactive = $input->isInteractive()
            && $output instanceof ConsoleOutput
            && stream_isatty($output->getStream())
            && stream_isatty(STDIN)
            && 'Windows' !== PHP_OS_FAMILY;

        return (new AuditTrailViewer($output, $interactive, $entries))->run();
    }
}
