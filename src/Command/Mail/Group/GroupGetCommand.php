<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:group:get', description: 'Show the recipients of a mail group (live Plesk state; --local shows desired state)')]
final class GroupGetCommand extends AbstractGroupCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com')
            ->addLocalOption()
        ;
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
                $marker = '0' === (string) $row['reconciled'] ? ' (pending)' : '';
                $output->writeln('  - '.$row['recipient_email'].$marker);
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

    private function showLive(OutputInterface $output, string $email): int
    {
        try {
            $recipients = $this->context()->gateway()->getForwarding($email);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('Group:        %s', $email));

        if ([] === $recipients) {
            $output->writeln('Recipients:   (none)');

            return self::SUCCESS;
        }

        $output->writeln('Recipients:');
        foreach ($recipients as $recipient) {
            $output->writeln('  - '.$recipient);
        }

        return self::SUCCESS;
    }
}
