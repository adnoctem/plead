<?php

declare(strict_types=1);

namespace App\Command\Server;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:admin', description: 'Show the Plesk Administrator personal information (live Plesk state)')]
final class ServerAdminCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $admin = $this->context()->gateway()->getAdminInfo();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('Name:      %s', $admin['pname']));
        $output->writeln(sprintf('Company:   %s', $admin['cname']));
        $output->writeln(sprintf('Email:     %s', $admin['email']));
        $output->writeln(sprintf('Phone:     %s', $admin['phone']));
        $output->writeln(sprintf('Fax:       %s', $admin['fax'] ?: '(none)'));
        $output->writeln(sprintf('Address:   %s', $admin['address']));
        $output->writeln(sprintf('City:      %s', $admin['city']));
        $output->writeln(sprintf('State:     %s', $admin['state']));
        $output->writeln(sprintf('Postcode:  %s', $admin['pcode']));
        $output->writeln(sprintf('Country:   %s', $admin['country']));
        $output->writeln(sprintf('Locale:    %s', $admin['locale']));
        $output->writeln(sprintf('Sessions:  %s', $admin['multiple_sessions']));

        return self::SUCCESS;
    }
}
