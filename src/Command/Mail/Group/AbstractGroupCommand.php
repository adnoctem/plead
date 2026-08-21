<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractGroupCommand extends AbstractMailCommand
{
    /** Seed the repository with current Plesk recipients on first touch. */
    protected function adoptIfNew(string $email): void
    {
        $this->context()->reconcilerMail()->adopt($email);
    }

    protected function applyAndReport(OutputInterface $output, string $email): void
    {
        $context = $this->context();
        $context->reconcilerMail()->reconcile($email);

        if ($context->dryRun()) {
            $output->writeln(sprintf('Recipients for <info>%s</info> would be updated (dry-run).', $email));

            return;
        }

        // A list still carrying unreconciled intents after the reconcile
        // attempt did not reach the server; the watcher will retry it.
        $stillDirty = in_array($email, $context->mailGroupRepository()->unreconciledLists(), true);
        if ($stillDirty) {
            $output->writeln(sprintf('<error>Recipients for %s could not be reconciled with the Plesk server.</error>', $email));
            $output->writeln('The change stays in the local queue; `mail:group:watch` will retry it. Check the sync log for details.');

            return;
        }

        $output->writeln(sprintf('Recipients for <info>%s</info> reconciled with the Plesk server.', $email));
    }

    /**
     * The merged mail.group entry defining a list, if any. Entries with a
     * domain-less address are matched by their composed address.
     *
     * @return null|array<string, mixed>
     */
    protected function configGroupEntry(string $email): ?array
    {
        $email = strtolower($email);
        foreach ($this->context()->config()['mail']['group'] ?? [] as $entry) {
            $address = strtolower((string) $entry['address']);
            $full = str_contains($address, '@')
                ? $address
                : $address.'@'.strtolower((string) ($entry['domain'] ?? ''));
            if ($full === $email) {
                return $entry;
            }
        }

        return null;
    }

    /** @return string[] */
    protected function parseRecipients(string $value): array
    {
        $recipients = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $recipient): bool => '' !== $recipient,
        ));

        foreach ($recipients as $recipient) {
            if (!str_contains($recipient, '@')) {
                throw new \InvalidArgumentException(sprintf('Not a valid recipient email address: "%s"', $recipient));
            }
        }

        return $recipients;
    }
}
