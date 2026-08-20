<?php

declare(strict_types=1);

namespace App\Command\Server\Extension;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:extension:call', description: 'Call an extension operation (extension id becomes the call element, params are its children)')]
final class ExtensionCallCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Extension id, e.g. git')
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation name exposed by the extension')
            ->addOption('param', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Operation parameter, name:value (repeatable)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        $operation = (string) $input->getArgument('operation');

        $params = [];
        foreach ((array) $input->getOption('param') as $param) {
            $parts = explode(':', $param, 2);
            if (2 !== count($parts) || '' === $parts[0]) {
                $output->writeln(sprintf('<error>Invalid --param "%s": use name:value.</error>', $param));

                return self::FAILURE;
            }
            $params[$parts[0]] = $parts[1];
        }

        $context = $this->context();

        // Audit first.
        $logId = $context->syncLogRepository()->logPending('server_extension', $id, 'call', [
            'id' => $id,
            'operation' => $operation,
            'params' => $params,
        ]);

        try {
            $context->gateway()->callExtension($id, $operation, $params);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Extension <info>%s</info> operation <info>%s</info> would be called (dry-run).', $id, $operation)
                : sprintf('Extension <info>%s</info> operation <info>%s</info> called.', $id, $operation));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
