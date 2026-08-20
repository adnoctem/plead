<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:list', description: 'List all domains on the server (live Plesk state)')]
final class DomainListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $domains = $this->context()->gateway()->listDomains();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $domains) {
            $output->writeln('No domains found on the server.');

            return self::SUCCESS;
        }

        foreach ($domains as $domain) {
            $output->writeln((string) $domain['name']);
        }

        return self::SUCCESS;
    }
}
