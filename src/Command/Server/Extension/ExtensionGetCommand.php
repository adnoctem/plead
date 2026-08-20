<?php

declare(strict_types=1);

namespace App\Command\Server\Extension;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:extension:get', description: 'Show one installed extension by id')]
final class ExtensionGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Extension id, e.g. wp-toolkit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');

        try {
            $extension = $this->context()->gateway()->getExtension($id);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if (null === $extension) {
            $output->writeln(sprintf('No extension with id <info>%s</info> on the server.', $id));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('ID:        %s', $extension['id']));
        $output->writeln(sprintf('Name:      %s', $extension['name']));
        $output->writeln(sprintf('Version:   %s', $extension['version']));
        $output->writeln(sprintf('Release:   %s', $extension['release']));
        $output->writeln(sprintf('Active:    %s', $extension['active'] ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
