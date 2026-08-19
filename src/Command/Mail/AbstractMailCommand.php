<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractMailCommand extends AbstractPleadCommand
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

        $output->writeln(sprintf('Recipients for <info>%s</info> reconciled with the Plesk server.', $email));
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
