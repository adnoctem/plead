<?php

declare(strict_types=1);

namespace App\Command\Mail\Alias;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractAliasCommand extends AbstractMailCommand
{
    /** Seed the repository with current Plesk aliases on first touch. */
    protected function adoptIfNew(string $email): void
    {
        $this->context()->reconcilerAlias()->adopt($email);
    }

    protected function applyAndReport(OutputInterface $output, string $email): void
    {
        $context = $this->context();
        $context->reconcilerAlias()->reconcile($email);

        if ($context->dryRun()) {
            $output->writeln(sprintf('Aliases for <info>%s</info> would be updated (dry-run).', $email));

            return;
        }

        // A mailbox still carrying unreconciled intents after the reconcile
        // attempt did not reach the server; the next reconcile will retry it.
        $stillDirty = in_array($email, $context->mailAliasRepository()->unreconciledLists(), true);
        if ($stillDirty) {
            $output->writeln(sprintf('<error>Aliases for %s could not be reconciled with the Plesk server.</error>', $email));
            $output->writeln('The change stays in the local queue; re-running the command will retry it. Check the sync log for details.');

            return;
        }

        $output->writeln(sprintf('Aliases for <info>%s</info> reconciled with the Plesk server.', $email));
    }

    /**
     * The server stores aliases as local parts (e.g. 'android'); accept both
     * that and the full address form, but never across domains.
     */
    protected function normalizeAlias(string $mailboxEmail, string $alias): string
    {
        if (!str_contains($alias, '@')) {
            return $alias;
        }

        $domain = explode('@', $mailboxEmail, 2)[1];
        if ($domain !== explode('@', $alias, 2)[1]) {
            throw new \InvalidArgumentException(sprintf('Alias "%s" must belong to the same domain as the mailbox.', $alias));
        }

        return explode('@', $alias, 2)[0];
    }

    protected function displayAlias(string $mailboxEmail, string $alias): string
    {
        return str_contains($alias, '@') ? $alias : $alias . '@' . explode('@', $mailboxEmail, 2)[1];
    }

    /** @return string[] */
    protected function parseAliases(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $alias): bool => '' !== $alias,
        ));
    }
}
