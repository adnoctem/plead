<?php

declare(strict_types=1);

namespace App\Command\Server\Ip;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:ip:list', description: 'List IP addresses on the server (live Plesk state)')]
final class IpListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $ips = $this->context()->gateway()->listIps();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $ips) {
            $output->writeln('No IP addresses on the server.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-16s %-10s %-10s %-16s %s', 'IP address', 'Type', 'Netmask', 'Interface', 'Public'));
        foreach ($ips as $ip) {
            $output->writeln(sprintf(
                '%-16s %-10s %-10s %-16s %s',
                $ip['ip_address'],
                $ip['type'],
                $ip['netmask'],
                $ip['interface'],
                $ip['public_ip_address'] ?: '(none)',
            ));
        }

        return self::SUCCESS;
    }
}
