<?php

declare(strict_types=1);

namespace App\Command\AutoReply;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auto-reply:get', description: 'Show the live auto-reply state configured on the Plesk server')]
final class AutoReplyGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address to inspect, e.g. user@company.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $autoresponder = $this->context()->gateway()->getAutoresponder($email);

        if (null === $autoresponder) {
            $output->writeln(sprintf('No autoresponder configured for <info>%s</info>.', $email));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Email:        %s', $email));
        $output->writeln(sprintf('Enabled:      %s', $autoresponder['enabled'] ? 'yes' : 'no'));
        $output->writeln(sprintf('Content type: %s', $autoresponder['content_type'] ?: '(unset)'));
        $output->writeln(sprintf('Charset:      %s', $autoresponder['charset'] ?: '(unset)'));
        $output->writeln(sprintf('Subject rule: %s', $autoresponder['subject'] ?: '(none)'));
        $output->writeln(sprintf('End date:     %s', $autoresponder['end_date'] ?: '(none)'));
        $output->writeln('Message:');
        $output->writeln($autoresponder['text']);

        return self::SUCCESS;
    }
}
