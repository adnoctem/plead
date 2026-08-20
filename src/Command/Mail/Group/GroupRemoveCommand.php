<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:group:remove', description: 'Remove a recipient from a mail group')]
final class GroupRemoveCommand extends AbstractGroupCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com')
            ->addArgument('recipient', InputArgument::REQUIRED, 'Recipient email address to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $recipient = (string) $input->getArgument('recipient');

        if (!str_contains($recipient, '@')) {
            $output->writeln(sprintf('<error>Not a valid recipient email address: %s</error>', $recipient));

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
        $logId = $context->syncLogRepository()->logPending('mail_group', $email, 'remove');
        $context->mailGroupRepository()->remove($email, $recipient);

        $this->applyAndReport($output, $email);
        $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

        return self::SUCCESS;
    }
}
