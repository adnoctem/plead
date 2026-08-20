<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:rename', description: 'Rename a mail address (local part only; the mailbox keeps its settings)')]
final class AddressRenameCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Current email address to rename')
            ->addArgument('new-name', InputArgument::REQUIRED, 'New local part, e.g. newuser (not a full email address)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $newName = (string) $input->getArgument('new-name');

        if (!str_contains($email, '@')) {
            $output->writeln(sprintf('<error>Not a valid email address: %s</error>', $email));

            return self::FAILURE;
        }

        if (str_contains($newName, '@')) {
            $output->writeln(sprintf('<error>--new-name must be the local part only, not a full email address: %s</error>', $newName));

            return self::FAILURE;
        }

        $context = $this->context();
        $newEmail = $newName . '@' . explode('@', $email, 2)[1];
        $logId = $context->syncLogRepository()->logPending('mail_address', $email, 'rename', [
            'from' => $email,
            'to' => $newEmail,
        ]);

        try {
            $context->gateway()->renameAddress($email, $newName);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

            if (!$context->dryRun()) {
                // The mailbox identity changed; move the local audit rows so
                // desired-state lookups keep working under the new name.
                $context->mailAddressRepository()->renameLocal($email, $newEmail);
            }

            $output->writeln($context->dryRun()
                ? sprintf('Mail address <info>%s</info> would be renamed to <info>%s</info> (dry-run).', $email, $newEmail)
                : sprintf('Mail address <info>%s</info> renamed to <info>%s</info>.', $email, $newEmail));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
