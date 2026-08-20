<?php

declare(strict_types=1);

namespace App\Command\Mail\Alias;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:alias:list', description: 'List mailboxes with aliases (live Plesk state; --local shows managed mailboxes)')]
final class AliasListCommand extends AbstractAliasCommand
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
        $rows = $this->context()->mailAliasRepository()->index();

        if ([] === $rows) {
            $output->writeln('No mailboxes managed by plead yet.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-45s %8s %8s %8s', 'Mailbox', 'Active', 'Removed', 'Pending'));
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-45s %8d %8d %8d',
                $row['email'],
                (int) $row['active_count'],
                (int) $row['removed_count'],
                (int) $row['pending_count'],
            ));
        }

        return self::SUCCESS;
    }

    private function listLive(OutputInterface $output): int
    {
        // The domain and mailname lookups run as ONE batched request each
        // (two HTTP round trips), then aliases are batched too.
        $gateway = $this->context()->gateway();

        try {
            $rows = $gateway->listMailnamesBulk(array_column($gateway->listDomains(), 'id'));
            $emails = array_map(
                static fn (array $row): string => $row['name'].'@'.$gateway->domainNameForSite((int) $row['site_id']),
                $rows,
            );
            $aliases = $gateway->getAliasesBulk($emails);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $found = false;
        foreach ($emails as $index => $email) {
            $addresses = $aliases[$email] ?? [];
            if ([] === $addresses) {
                continue;
            }

            $found = true;
            $output->writeln(sprintf(
                '%s (%d alias%s)',
                $email,
                count($addresses),
                1 === count($addresses) ? '' : 'es',
            ));
            foreach ($addresses as $alias) {
                $output->writeln('  - '.$this->displayAlias($email, $alias));
            }
        }

        if (!$found) {
            $output->writeln('No mailboxes with aliases found on the server.');
        }

        return self::SUCCESS;
    }
}
