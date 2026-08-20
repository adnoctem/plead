<?php

declare(strict_types=1);

namespace App\Command\Server\Ip;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:ip:set', description: 'Set IP address properties (--type, --public-ip)')]
final class IpSetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('ip', InputArgument::REQUIRED, 'IP address to update')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Type: shared|exclusive')
            ->addOption('public-ip', null, InputOption::VALUE_REQUIRED, 'Public IP address (for NAT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ipAddress = (string) $input->getArgument('ip');
        $type = $input->getOption('type');
        $publicIp = $input->getOption('public-ip');

        if (null === $type && null === $publicIp) {
            $output->writeln('<error>Provide --type and/or --public-ip.</error>');

            return self::FAILURE;
        }

        $properties = [];
        if (null !== $type) {
            $type = strtolower((string) $type);
            if (!in_array($type, ['shared', 'exclusive'], true)) {
                $output->writeln(sprintf('<error>Invalid value for --type: "%s". Use shared or exclusive.</error>', $type));

                return self::FAILURE;
            }
            $properties['type'] = $type;
        }
        if (null !== $publicIp) {
            $properties['public_ip_address'] = (string) $publicIp;
        }

        $context = $this->context();

        // Audit the change with the ORIGINAL values: read the current state
        // first. A read failure does not block the mutation.
        $old = [];
        try {
            foreach ($context->gateway()->listIps() as $ip) {
                if ($ip['ip_address'] === $ipAddress) {
                    if (isset($properties['type'])) {
                        $old['type'] = $ip['type'];
                    }
                    if (isset($properties['public_ip_address'])) {
                        $old['public_ip_address'] = $ip['public_ip_address'];
                    }
                    break;
                }
            }
        } catch (\Throwable) {
            // Ignore: the mutation below will surface connectivity problems.
        }

        $details = ['new' => $properties];
        if ([] !== $old) {
            $details['old'] = $old;
        }

        $logId = $context->syncLogRepository()->logPending('server_ip', $ipAddress, 'set', $details);

        try {
            $context->gateway()->setIp($ipAddress, $properties);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('IP address <info>%s</info> would be updated (dry-run).', $ipAddress)
                : sprintf('IP address <info>%s</info> updated.', $ipAddress));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
