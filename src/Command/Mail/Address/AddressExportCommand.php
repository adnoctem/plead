<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:export', description: 'Export all addresses (live Plesk state) as CSV or JSON')]
final class AddressExportCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Only export addresses on this domain')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export format: csv or json', 'csv')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to a file instead of stdout')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['csv', 'json'], true)) {
            $output->writeln('<error>--format must be "csv" or "json".</error>');

            return self::FAILURE;
        }

        $rows = $this->collect((string) $input->getOption('domain'), $output);
        if (null === $rows) {
            return self::FAILURE;
        }

        $content = 'csv' === $format ? $this->toCsv($rows) : $this->toJson($rows);

        $target = (string) $input->getOption('output');
        if ('' !== $target) {
            if (false === file_put_contents($target, $content)) {
                $output->writeln(sprintf('<error>Unable to write export to %s</error>', $target));

                return self::FAILURE;
            }

            $output->writeln(sprintf('Exported %d address%s to %s', count($rows), 1 === count($rows) ? '' : 'es', $target));

            return self::SUCCESS;
        }

        $output->write($content);

        return self::SUCCESS;
    }

    /** @return null|array<int, array<string, string>> */
    private function collect(string $domainFilter, OutputInterface $output): ?array
    {
        $gateway = $this->context()->gateway();

        $rows = [];

        try {
            $domains = $gateway->listDomains();
            $siteIds = [];
            $domainBySite = [];
            foreach ($domains as $domain) {
                $domainBySite[(int) $domain['id']] = (string) $domain['name'];
                if ('' !== $domainFilter && $domainFilter !== $domain['name']) {
                    continue;
                }

                $siteIds[] = (int) $domain['id'];
            }

            // Batched lookups: mailnames in one packet, mailbox details in
            // another - three HTTP round trips total.
            $mailnames = $gateway->listMailnamesBulk($siteIds);
            $emails = array_map(
                static fn (array $row): string => $row['name'].'@'.$domainBySite[$row['site_id']],
                $mailnames,
            );
            $infos = $gateway->getMailboxInfoBulk($emails);

            foreach ($mailnames as $index => $mailname) {
                $email = $emails[$index];
                $info = $infos[$email] ?? null;

                $rows[] = [
                    'address' => $email,
                    'domain' => $domainBySite[$mailname['site_id']],
                    'description' => (string) $mailname['description'],
                    'mailbox_enabled' => null !== $info && $info['mailbox_enabled'] ? 'true' : 'false',
                    'forwarding_count' => null !== $info ? (string) count($info['forwarding']) : '0',
                    'autoresponder_enabled' => null !== $info && $info['autoresponder_enabled'] ? 'true' : 'false',
                ];
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return null;
        }

        return $rows;
    }

    /** @param array<int, array<string, string>> $rows */
    private function toCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Unable to open a temporary stream for CSV export.');
        }

        // escape: '' avoids the deprecated implicit backslash default (PHP 8.5)
        fputcsv($stream, ['address', 'domain', 'description', 'mailbox_enabled', 'forwarding_count', 'autoresponder_enabled'], escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @param array<int, array<string, string>> $rows */
    private function toJson(array $rows): string
    {
        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
