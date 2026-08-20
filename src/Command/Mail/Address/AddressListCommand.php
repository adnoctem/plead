<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:list', description: 'List all mail addresses (live Plesk state; --local shows locally known addresses)')]
final class AddressListCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addLocalOption()
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Only list addresses on this domain')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->isLocal($input)
            ? $this->listLocal($output)
            : $this->listLive($output, (string) $input->getOption('domain'));
    }

    private function listLocal(OutputInterface $output): int
    {
        $rows = $this->context()->mailGroupRepository()->addressIndex();

        if ([] === $rows) {
            $output->writeln('No addresses known to plead yet.');

            return self::SUCCESS;
        }

        // Group memberships per address.
        $memberships = [];
        foreach ($rows as $row) {
            $memberships[$row['recipient_email']][] = $row;
        }

        foreach ($memberships as $address => $groups) {
            $output->writeln($address.':');
            foreach ($groups as $group) {
                $marker = null !== $group['removed_at']
                    ? ' (removed)'
                    : ('0' === (string) $group['reconciled'] ? ' (pending)' : '');
                $output->writeln('  - '.$group['list_email'].$marker);
            }
        }

        return self::SUCCESS;
    }

    private function listLive(OutputInterface $output, string $domainFilter): int
    {
        $gateway = $this->context()->gateway();

        try {
            $domains = $gateway->listDomains();
            $domainBySite = [];
            $siteIds = [];
            foreach ($domains as $domain) {
                $domainBySite[(int) $domain['id']] = (string) $domain['name'];
                if ('' !== $domainFilter && $domainFilter !== $domain['name']) {
                    continue;
                }

                $siteIds[] = (int) $domain['id'];
            }

            // One webspace-get plus ONE batched packet of mail get_info
            // queries - two HTTP round trips no matter how many domains.
            $rows = $gateway->listMailnamesBulk($siteIds);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $rows) {
            $output->writeln('No mail addresses found on the server.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $suffix = '' !== $row['description'] ? sprintf('  (%s)', $row['description']) : '';
            $output->writeln($row['name'].'@'.$domainBySite[$row['site_id']].$suffix);
        }

        return self::SUCCESS;
    }
}
