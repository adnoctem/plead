<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:list', description: 'Show the locally managed recipients of a mail group (from SQLite)')]
final class MailListCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $history = $this->context()->mailGroupRepository()->history($email);

        $output->writeln(sprintf('Group: %s', $email));

        if ([] === $history) {
            $output->writeln('Not managed by plead yet.');

            return self::SUCCESS;
        }

        $active = array_filter($history, static fn (array $row): bool => null === $row['removed_at']);
        $removed = array_filter($history, static fn (array $row): bool => null !== $row['removed_at']);

        if ([] !== $active) {
            $output->writeln('Active recipients:');
            foreach ($active as $row) {
                $output->writeln('  - ' . $row['recipient_email']);
            }
        } else {
            $output->writeln('Active recipients: (none)');
        }

        if ([] !== $removed) {
            $output->writeln('Removed (history):');
            foreach ($removed as $row) {
                $output->writeln(sprintf('  - %s (removed %s)', $row['recipient_email'], $row['removed_at']));
            }
        }

        return self::SUCCESS;
    }
}
