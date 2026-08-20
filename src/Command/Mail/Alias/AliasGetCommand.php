<?php

declare(strict_types=1);

namespace App\Command\Mail\Alias;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:alias:get', description: 'Show the aliases of a mailbox (live Plesk state; --local shows desired state)')]
final class AliasGetCommand extends AbstractAliasCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Mailbox email address, e.g. user@company.com')
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
        $history = $this->context()->mailAliasRepository()->history($email);

        $output->writeln(sprintf('Mailbox: %s', $email));

        if ([] === $history) {
            $output->writeln('Not managed by plead yet.');

            return self::SUCCESS;
        }

        $active = array_filter($history, static fn (array $row): bool => null === $row['removed_at']);
        $removed = array_filter($history, static fn (array $row): bool => null !== $row['removed_at']);

        if ([] !== $active) {
            $output->writeln('Active aliases:');
            foreach ($active as $row) {
                $marker = '0' === (string) $row['reconciled'] ? ' (pending)' : '';
                $output->writeln('  - '.$this->displayAlias($email, (string) $row['alias_email']).$marker);
            }
        } else {
            $output->writeln('Active aliases: (none)');
        }

        if ([] !== $removed) {
            $output->writeln('Removed (history):');
            foreach ($removed as $row) {
                $output->writeln(sprintf('  - %s (removed %s)', $this->displayAlias($email, (string) $row['alias_email']), $row['removed_at']));
            }
        }

        return self::SUCCESS;
    }

    private function showLive(OutputInterface $output, string $email): int
    {
        try {
            $aliases = $this->context()->gateway()->getAliases($email);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('Mailbox:  %s', $email));

        if ([] === $aliases) {
            $output->writeln('Aliases:  (none)');

            return self::SUCCESS;
        }

        $output->writeln('Aliases:');
        foreach ($aliases as $alias) {
            $output->writeln('  - '.$this->displayAlias($email, $alias));
        }

        return self::SUCCESS;
    }
}
