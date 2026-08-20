<?php

declare(strict_types=1);

namespace App\Command\Server\Extension;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:extension:install', description: 'Install an extension by id or from a URL')]
final class ExtensionInstallCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Extension id to install')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Extension package URL to install from');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getArgument('id') ? (string) $input->getArgument('id') : null;
        $url = $input->getOption('url') ? (string) $input->getOption('url') : null;

        if (null === $id && null === $url) {
            $output->writeln('<error>Provide the extension <id> argument or --url.</error>');

            return self::FAILURE;
        }

        $context = $this->context();

        // Audit first.
        $details = [];
        if (null !== $id) {
            $details['id'] = $id;
        } else {
            $details['url'] = $url;
        }
        $logId = $context->syncLogRepository()->logPending('server_extension', $id ?? (string) $url, 'install', $details);

        try {
            $context->gateway()->installExtension($id, $url);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Extension <info>%s</info> would be installed (dry-run).', $id ?? $url)
                : sprintf('Extension <info>%s</info> installed.', $id ?? $url));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
