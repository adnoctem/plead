<?php

declare(strict_types=1);

namespace App\Command\Mail\Autoresponder;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:autoresponder:get', description: 'Show the auto-reply of an address (live Plesk state; --local shows desired state)')]
final class AutoresponderGetCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to inspect, e.g. user@company.com')
            ->addLocalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');

        return $this->isLocal($input)
            ? $this->showLocal($output, $email)
            : $this->showLive($output, $email);
    }

    private function showLocal(OutputInterface $output, string $email): int
    {
        $row = $this->context()->autoReplyRepository()->find($email);

        if (null === $row) {
            $output->writeln(sprintf('No scheduled auto-reply for <info>%s</info>.', $email));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Email:     %s', $row['email']));
        $output->writeln(sprintf('Status:    %s', $row['status']));
        $output->writeln(sprintf('State:     %s', '1' === (string) $row['reconciled'] ? 'reconciled' : 'pending'));
        $output->writeln(sprintf('Start:     %s', $row['start_date']));
        $output->writeln(sprintf('End:       %s', $row['end_date']));
        $output->writeln(sprintf('Reconciled:%s', null !== $row['reconciled_at'] ? ' ' . $row['reconciled_at'] : ' (not yet)'));
        $output->writeln('Message:');
        $output->writeln($row['message']);

        return self::SUCCESS;
    }

    private function showLive(OutputInterface $output, string $email): int
    {
        try {
            $autoresponder = $this->context()->gateway()->getAutoresponder($email);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

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
