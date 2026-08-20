<?php

declare(strict_types=1);

namespace App\Command\Server\Components;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:components:install', description: 'Install a Plesk component (packet shape per official docs; live validation pending)')]
final class ComponentsInstallCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('component-id', InputArgument::REQUIRED, 'Component id, e.g. fail2ban')
            ->addOption('update-id', null, InputOption::VALUE_REQUIRED, 'Update id the component belongs to (required by the docs shape)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $componentId = (string) $input->getArgument('component-id');

        if (null === $input->getOption('update-id')) {
            $output->writeln('<error>Provide --update-id.</error>');

            return self::FAILURE;
        }
        $updateId = (string) $input->getOption('update-id');

        $context = $this->context();

        // Audit first.
        $logId = $context->syncLogRepository()->logPending('server_component', $componentId, 'install', [
            'component_id' => $componentId,
            'update_id' => $updateId,
        ]);

        try {
            $context->gateway()->installComponent($componentId, $updateId);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Component <info>%s</info> would be installed (dry-run).', $componentId)
                : sprintf('Component <info>%s</info> installed.', $componentId));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
