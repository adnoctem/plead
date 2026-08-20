<?php

declare(strict_types=1);

namespace App\Command\Server\Service;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared audit-first mutation for the service verbs (start/stop/restart).
 * The Plesk API exposes them as <server><srv_man><id>..</id><operation>..
 * — special verbs are deliberate here, the uniform list/get/set does not
 * map onto service lifecycle.
 */
abstract class AbstractServiceCommand extends AbstractPleadCommand
{
    abstract protected function operation(): string;

    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::REQUIRED, 'Service id, e.g. web, mail, dns');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = (string) $input->getArgument('service');
        $operation = $this->operation();
        $context = $this->context();

        // Audit first: the intent is recorded before the RPC.
        $logId = $context->syncLogRepository()->logPending('server_service', $service, $operation);

        try {
            $context->gateway()->manageService($service, $operation);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Service <info>%s</info> would be %sed (dry-run).', $service, $operation)
                : sprintf('Service <info>%s</info> %sed.', $service, $operation));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
