<?php

declare(strict_types=1);

namespace App\Command\Server\Ip;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:ip:remove', description: 'Remove an IP address from the server')]
final class IpRemoveCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('ip', InputArgument::REQUIRED, 'IP address to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ipAddress = (string) $input->getArgument('ip');
        $context = $this->context();

        // Audit first.
        $logId = $context->syncLogRepository()->logPending('server_ip', $ipAddress, 'remove');

        try {
            $context->gateway()->removeIp($ipAddress);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('IP address <info>%s</info> would be removed (dry-run).', $ipAddress)
                : sprintf('IP address <info>%s</info> removed.', $ipAddress));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
