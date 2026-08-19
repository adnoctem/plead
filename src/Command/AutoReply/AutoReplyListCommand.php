<?php

declare(strict_types=1);

namespace App\Command\AutoReply;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auto-reply:list', description: 'Show the locally scheduled auto-reply for an email (from SQLite)')]
final class AutoReplyListCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address to inspect, e.g. user@company.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $row = $this->context()->autoReplyRepository()->find($email);

        if (null === $row) {
            $output->writeln(sprintf('No scheduled auto-reply for <info>%s</info>.', $email));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Email:     %s', $row['email']));
        $output->writeln(sprintf('Start:     %s', $row['start_date']));
        $output->writeln(sprintf('End:       %s', $row['end_date']));
        $output->writeln(sprintf('Applied:   %s', $row['applied_at'] ?? '(not yet applied)'));
        $output->writeln('Message:');
        $output->writeln($row['message']);

        return self::SUCCESS;
    }
}
