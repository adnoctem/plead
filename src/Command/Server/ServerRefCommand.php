<?php

declare(strict_types=1);

namespace App\Command\Server;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:ref', description: 'Show the REST CLI-gate commands (no arg) or the reference of one command (id)')]
final class ServerRefCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'CLI command id to show the reference for, e.g. extension');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id');

        try {
            $gateway = $this->context()->restGateway();
            if (null === $id) {
                $this->listCommands($output, $gateway->cliCommands());

                return self::SUCCESS;
            }

            $this->showReference($output, (string) $id, $gateway->cliRef((string) $id));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /** @param string[] $commands */
    private function listCommands(OutputInterface $output, array $commands): void
    {
        if ([] === $commands) {
            $output->writeln('No CLI commands available on the server.');

            return;
        }

        $output->writeln(sprintf('%d CLI commands available (server:exec <id> <args...>):', count($commands)));
        foreach ($commands as $command) {
            $output->writeln('  '.$command);
        }
    }

    /** @param array<string, mixed> $reference */
    private function showReference(OutputInterface $output, string $id, array $reference): void
    {
        $output->writeln(sprintf('Reference for <info>%s</info>:', $id));

        $commands = $reference['allowed_commands'] ?? [];
        if (is_array($commands) && [] !== $commands) {
            $output->writeln('Commands:');
            foreach ($commands as $command) {
                if (!is_array($command)) {
                    continue;
                }
                $name = (string) ($command['name'] ?? '');
                $usage = (string) ($command['usage'] ?? '');
                $info = (string) ($command['info'] ?? '');
                $output->writeln(sprintf('  --%s', $name));
                if ('' !== $usage) {
                    $output->writeln(sprintf('    usage: %s', $usage));
                }
                if ('' !== $info) {
                    $output->writeln(sprintf('    %s', $info));
                }
            }
        }

        $options = $reference['allowed_options'] ?? [];
        if (is_array($options) && [] !== $options) {
            $output->writeln('Options:');
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $name = (string) ($option['name'] ?? '');
                $usage = (string) ($option['usage'] ?? '');
                $info = (string) ($option['info'] ?? '');
                $output->writeln(sprintf('  -%s', $name));
                if ('' !== $usage) {
                    $output->writeln(sprintf('    usage: %s', $usage));
                }
                if ('' !== $info) {
                    $output->writeln(sprintf('    %s', $info));
                }
            }
        }

        if ((!is_array($commands) || [] === $commands) && (!is_array($options) || [] === $options)) {
            $output->writeln('  (no commands or options documented)');
        }
    }
}
