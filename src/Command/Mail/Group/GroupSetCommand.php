<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:group:set', description: 'Replace the full recipient list of a mail group')]
final class GroupSetCommand extends AbstractGroupCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com')
            ->addOption('recipients', null, InputOption::VALUE_REQUIRED, 'Comma-separated recipient email addresses');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');

        try {
            $recipients = $this->parseRecipients((string) $input->getOption('recipients'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $recipients) {
            $output->writeln('<error>--recipients must contain at least one address.</error>');

            return self::FAILURE;
        }

        $context = $this->context();
        $repository = $context->mailGroupRepository();
        try {
            $this->adoptIfNew($email);
        } catch (\Throwable $e) {
            // Seeding from Plesk failed; aborting keeps the local state free
            // of blind mutations (read-before-write).
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        // Audit first: the local mutation below is the intent.
        $logId = $context->syncLogRepository()->logPending('mail_group', $email, 'set', ['recipients' => $recipients]);

        foreach ($recipients as $recipient) {
            $repository->upsertActive($email, $recipient);
        }
        foreach ($repository->activeRecipients($email) as $existing) {
            if (!in_array($existing, $recipients, true)) {
                $repository->remove($email, $existing);
            }
        }

        $this->applyAndReport($output, $email);
        $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

        return self::SUCCESS;
    }
}
