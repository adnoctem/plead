<?php

declare(strict_types=1);

namespace App\Command\Server\Extension;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:extension:uninstall', description: 'Uninstall an extension by id')]
final class ExtensionUninstallCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Extension id to uninstall');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        $context = $this->context();

        // Audit first.
        $logId = $context->syncLogRepository()->logPending('server_extension', $id, 'uninstall', ['id' => $id]);

        try {
            $context->gateway()->uninstallExtension($id);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Extension <info>%s</info> would be uninstalled (dry-run).', $id)
                : sprintf('Extension <info>%s</info> uninstalled.', $id));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
