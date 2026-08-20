<?php

declare(strict_types=1);

namespace App\Command\Mail\Alias;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:alias:remove', description: 'Remove an alias address from a mailbox')]
final class AliasRemoveCommand extends AbstractAliasCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Mailbox email address, e.g. user@company.com')
            ->addArgument('alias', InputArgument::REQUIRED, 'Alias email address to remove')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $alias = (string) $input->getArgument('alias');

        try {
            $alias = $this->normalizeAlias($email, $alias);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $context = $this->context();

        try {
            $this->adoptIfNew($email);
        } catch (\Throwable $e) {
            // Seeding from Plesk failed; aborting keeps the local state free
            // of blind mutations (read-before-write).
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        // Audit first: the soft-delete below is the intent; the row is kept
        // so the removal stays visible in the audit trail.
        $logId = $context->syncLogRepository()->logPending('mail_alias', $email, 'remove', ['alias' => $alias]);
        $context->mailAliasRepository()->remove($email, $alias);

        $this->applyAndReport($output, $email);
        $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

        return self::SUCCESS;
    }
}
