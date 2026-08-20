<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:traffic:get', description: 'Show traffic usage of a domain between two dates (live Plesk state)')]
final class DomainTrafficGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name, e.g. delta4x4.net')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date, YYYY-MM-DD')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date, YYYY-MM-DD')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');

        foreach (['from', 'to'] as $option) {
            $value = $input->getOption($option);
            if (null !== $value && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                $output->writeln(sprintf('<error>--%s must be YYYY-MM-DD, got: %s</error>', $option, $value));

                return self::FAILURE;
            }
        }

        try {
            $rows = $this->context()->gateway()->getSiteTraffic(
                $domain,
                $input->getOption('from') ? (string) $input->getOption('from') : null,
                $input->getOption('to') ? (string) $input->getOption('to') : null,
            );
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $rows) {
            $output->writeln(sprintf('No traffic records for <info>%s</info> in the given window.', $domain));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Traffic for %s:', $domain));
        $output->writeln(sprintf('%-12s %10s %10s %10s %10s %10s %10s', 'Date', 'HTTP in', 'HTTP out', 'FTP in', 'FTP out', 'SMTP in', 'SMTP out'));
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-12s %10s %10s %10s %10s %10s %10s',
                $row['date'],
                $row['http_in'],
                $row['http_out'],
                $row['ftp_in'],
                $row['ftp_out'],
                $row['smtp_in'],
                $row['smtp_out'],
            ));
        }

        return self::SUCCESS;
    }
}
