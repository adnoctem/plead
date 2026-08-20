<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:descriptor', description: 'Show the hosting settings descriptor of a domain (property names, types, defaults)')]
final class DomainDescriptorCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('domain', InputArgument::REQUIRED, 'Domain name, e.g. delta4x4.net');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');

        try {
            $properties = $this->context()->gateway()->getHostingDescriptor($domain);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $properties) {
            $output->writeln(sprintf('No hosting descriptor for <info>%s</info>.', $domain));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Hosting descriptor for %s:', $domain));
        $output->writeln(sprintf('%-28s %-14s %-24s %s', 'Property', 'Type', 'Default', 'Label'));
        foreach ($properties as $property) {
            $output->writeln(sprintf(
                '%-28s %-14s %-24s %s',
                $property['name'],
                $property['type'],
                $property['default'],
                $property['label'],
            ));
        }

        return self::SUCCESS;
    }
}
