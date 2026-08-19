<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:get', description: 'Show the live forwarding recipients of a mail group on the Plesk server')]
final class MailGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $recipients = $this->context()->gateway()->getForwarding($email);

        $output->writeln(sprintf('Group:        %s', $email));

        if ([] === $recipients) {
            $output->writeln('Recipients:   (none)');

            return self::SUCCESS;
        }

        $output->writeln('Recipients:');
        foreach ($recipients as $recipient) {
            $output->writeln('  - ' . $recipient);
        }

        return self::SUCCESS;
    }
}
