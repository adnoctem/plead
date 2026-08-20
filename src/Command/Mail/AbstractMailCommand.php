<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractMailCommand extends AbstractPleadCommand
{
    protected function addLocalOption(): static
    {
        // Read commands default to the live Plesk server; --local reads the
        // local SQLite state (desired state / audit trail) instead.
        $this->addOption('local', null, InputOption::VALUE_NONE, 'Read from the local SQLite state instead of the live Plesk server');

        return $this;
    }

    protected function isLocal(InputInterface $input): bool
    {
        return (bool) $input->getOption('local');
    }

    /**
     * Audit-first mailbox mutation: record the intent in the sync log before
     * the RPC, then finalize it. Mailbox operations are one-shot - unlike
     * auto-replies and group recipients they have no desired-state row, so
     * the sync log is their only local record.
     */
    protected function mutateAddress(
        OutputInterface $output,
        string $email,
        string $action,
        callable $mutation,
        string $doneMessage,
        string $dryRunMessage,
    ): int {
        $context = $this->context();
        $logId = $context->syncLogRepository()->logPending('mail_address', $email, $action);

        try {
            $mutation($email);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun() ? sprintf($dryRunMessage, $email) : sprintf($doneMessage, $email));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
