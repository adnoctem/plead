<?php

declare(strict_types=1);

namespace App\Command\Mail\Autoresponder;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:autoresponder:list', description: 'List all addresses with an auto-reply (live Plesk state; --local shows scheduled entries)')]
final class AutoresponderListCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this->addLocalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->isLocal($input) ? $this->listLocal($output) : $this->listLive($output);
    }

    private function listLocal(OutputInterface $output): int
    {
        $rows = $this->context()->autoReplyRepository()->allEntries();

        if ([] === $rows) {
            $output->writeln('No scheduled auto-replies in the local state.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-45s %-10s %-10s %-22s %-22s', 'Email', 'Status', 'State', 'Start', 'End'));
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-45s %-10s %-10s %-22s %-22s',
                $row['email'],
                $row['status'],
                '0' === (string) $row['reconciled'] ? 'pending' : 'reconciled',
                $row['start_date'],
                $row['end_date'],
            ));
        }

        return self::SUCCESS;
    }

    private function listLive(OutputInterface $output): int
    {
        // An address "has an auto-reply" on Plesk when its autoresponder is
        // enabled. Domain and mailname lookups run as ONE batched request
        // each, then the autoresponder state is batched too - three HTTP
        // round trips total.
        $gateway = $this->context()->gateway();

        try {
            $rows = $gateway->listMailnamesBulk(array_column($gateway->listDomains(), 'id'));
            $pairs = [];
            foreach ($rows as $row) {
                $email = $row['name'].'@'.$gateway->domainNameForSite((int) $row['site_id']);
                $pairs[] = ['email' => $email];
            }

            $autoresponders = $gateway->getAutoresponderBulk(array_column($pairs, 'email'));
            foreach ($pairs as &$pair) {
                $pair['autoresponder'] = $autoresponders[$pair['email']] ?? null;
            }
            unset($pair);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $found = false;
        foreach ($pairs as $pair) {
            $autoresponder = $pair['autoresponder'];
            if (null === $autoresponder || !$autoresponder['enabled']) {
                continue;
            }

            $found = true;
            $suffix = null !== $autoresponder['end_date'] ? sprintf(' (until %s)', $autoresponder['end_date']) : '';
            $output->writeln($pair['email'].$suffix);
        }

        if (!$found) {
            $output->writeln('No addresses with an enabled auto-reply found on the server.');
        }

        return self::SUCCESS;
    }
}
