<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:remove', description: 'Remove a domain (site) from the server')]
final class DomainRemoveCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('domain', InputArgument::REQUIRED, 'Domain name to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');
        $context = $this->context();

        // Audit first.
        $logId = $context->syncLogRepository()->logPending('domain', $domain, 'remove');

        try {
            $context->gateway()->removeSite($domain);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Domain <info>%s</info> would be removed (dry-run).', $domain)
                : sprintf('Domain <info>%s</info> removed.', $domain));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
