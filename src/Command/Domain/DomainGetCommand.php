<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:get', description: 'Show everything plead can read about a domain (live Plesk state)')]
final class DomainGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('domain', InputArgument::REQUIRED, 'Domain name, e.g. delta4x4.net');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');
        $gateway = $this->context()->gateway();

        try {
            $info = $gateway->getDomain($domain);
            if (null === $info) {
                $output->writeln(sprintf('No domain <info>%s</info> found on the server.', $domain));

                return self::SUCCESS;
            }

            $mailnames = count($gateway->listMailnames((int) $info['id']));
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('Name:             %s', $info['name']));
        $output->writeln(sprintf('Status:           %s', '0' === $info['status'] ? 'enabled' : 'disabled'));
        $output->writeln(sprintf('Hosting type:     %s', $info['htype']));
        $output->writeln(sprintf('Created:          %s', $info['cr_date']));
        $output->writeln(sprintf('Real size:        %s', $info['real_size']));
        $output->writeln(sprintf('Owner:            %s', $info['owner_login']));
        $output->writeln(sprintf('IP addresses:     %s', implode(', ', $info['ip_addresses'])));
        $output->writeln(sprintf('GUID:             %s', $info['guid']));
        $output->writeln(sprintf('Description:      %s', $info['description'] ?: '(none)'));
        $output->writeln(sprintf('Admin note:       %s', $info['admin_description'] ?: '(none)'));
        $output->writeln(sprintf('Mail addresses:   %d', $mailnames));

        if (isset($info['hosting'])) {
            $output->writeln('Hosting:');
            foreach ($info['hosting'] as $key => $value) {
                $output->writeln(sprintf('  %-14s %s', $key, $value));
            }
        }

        foreach (['limits', 'prefs'] as $section) {
            if (isset($info[$section]) && [] !== $info[$section]) {
                $output->writeln(ucfirst($section).':');
                foreach ($info[$section] as $key => $value) {
                    $output->writeln(sprintf('  %-20s %s', $key, $value));
                }
            }
        }

        return self::SUCCESS;
    }
}
