<?php

declare(strict_types=1);

namespace App\Command\Server\Ip;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:ip:get', description: 'Show one IP address (the API has no single-IP read; the list is filtered)')]
final class IpGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('ip', InputArgument::REQUIRED, 'IP address to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ipAddress = (string) $input->getArgument('ip');

        try {
            foreach ($this->context()->gateway()->listIps() as $ip) {
                if ($ip['ip_address'] === $ipAddress) {
                    $output->writeln(sprintf('IP address:   %s', $ip['ip_address']));
                    $output->writeln(sprintf('Type:         %s', $ip['type']));
                    $output->writeln(sprintf('Netmask:      %s', $ip['netmask']));
                    $output->writeln(sprintf('Interface:    %s', $ip['interface']));
                    $output->writeln(sprintf('Public IP:    %s', $ip['public_ip_address'] ?: '(none)'));

                    return self::SUCCESS;
                }
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('No IP address <info>%s</info> on the server.', $ipAddress));

        return self::SUCCESS;
    }
}
