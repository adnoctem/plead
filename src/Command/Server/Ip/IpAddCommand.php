<?php

declare(strict_types=1);

namespace App\Command\Server\Ip;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:ip:add', description: 'Add an IP address to the server (shared or exclusive)')]
final class IpAddCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('ip', InputArgument::REQUIRED, 'IP address to add')
            ->addOption('netmask', null, InputOption::VALUE_REQUIRED, 'Netmask, e.g. 255.255.255.0')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Type: shared|exclusive')
            ->addOption('interface', null, InputOption::VALUE_REQUIRED, 'Server network interface, e.g. eth0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ipAddress = (string) $input->getArgument('ip');

        foreach (['netmask', 'interface'] as $required) {
            if (null === $input->getOption($required)) {
                $output->writeln(sprintf('<error>Provide --%s.</error>', $required));

                return self::FAILURE;
            }
        }

        $type = $input->getOption('type') ? strtolower((string) $input->getOption('type')) : 'shared';
        if (!in_array($type, ['shared', 'exclusive'], true)) {
            $output->writeln(sprintf('<error>Invalid value for --type: "%s". Use shared or exclusive.</error>', $type));

            return self::FAILURE;
        }

        $netmask = (string) $input->getOption('netmask');
        $interface = (string) $input->getOption('interface');
        $context = $this->context();

        // Audit first: record the intent with the values involved.
        $logId = $context->syncLogRepository()->logPending('server_ip', $ipAddress, 'add', [
            'ip_address' => $ipAddress,
            'netmask' => $netmask,
            'type' => $type,
            'interface' => $interface,
        ]);

        try {
            $context->gateway()->addIp($ipAddress, $netmask, $type, $interface);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('IP address <info>%s</info> would be added (dry-run).', $ipAddress)
                : sprintf('IP address <info>%s</info> added.', $ipAddress));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
