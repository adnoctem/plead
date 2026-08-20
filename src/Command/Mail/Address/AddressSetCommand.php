<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:set', description: 'Set properties of an address (--description, --outgoing-limit, --quota, --antivir)')]
final class AddressSetCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to update')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'New description for the address')
            ->addOption('outgoing-limit', null, InputOption::VALUE_REQUIRED, 'New outgoing-messages limit per mailbox (0 = no limit)')
            ->addOption('quota', null, InputOption::VALUE_REQUIRED, 'New mailbox size limit in MiB (e.g. 512)')
            ->addOption('antivir', null, InputOption::VALUE_REQUIRED, 'Antivirus mode: off|in|out|inout (validated enum)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $description = $input->getOption('description');
        $outgoingLimit = $input->getOption('outgoing-limit');
        $quota = $input->getOption('quota');
        $antivir = $input->getOption('antivir');

        if (null === $description && null === $outgoingLimit && null === $quota && null === $antivir) {
            $output->writeln('<error>Provide --description, --outgoing-limit, --quota and/or --antivir.</error>');

            return self::FAILURE;
        }

        $properties = [];
        if (null !== $description) {
            $properties['description'] = (string) $description;
        }

        if (null !== $outgoingLimit) {
            $limit = (string) $outgoingLimit;
            if (!preg_match('/^\d+$/', $limit)) {
                $output->writeln(sprintf('<error>--outgoing-limit must be a non-negative integer, got: %s</error>', $limit));

                return self::FAILURE;
            }
            $properties['outgoing-messages-mbox-limit'] = $limit;
        }

        if (null !== $antivir) {
            $antivir = strtolower((string) $antivir);
            if (!in_array($antivir, ['off', 'in', 'out', 'inout'], true)) {
                $output->writeln(sprintf(
                    '<error>Invalid value for --antivir: "%s". Use off, in, out or inout (server enum).</error>',
                    $antivir,
                ));

                return self::FAILURE;
            }
            $properties['antivir'] = $antivir;
        }

        $quotaMiB = null;
        if (null !== $quota) {
            $quotaMiB = (string) $quota;
            if (!preg_match('/^\d+$/', $quotaMiB) || 0 === (int) $quotaMiB) {
                $output->writeln(sprintf('<error>--quota must be a positive integer (MiB), got: %s</error>', $quotaMiB));

                return self::FAILURE;
            }
            $quotaMiB = (int) $quotaMiB;
        }

        $context = $this->context();

        // Audit the change with the ORIGINAL values: read the current state
        // first. A read failure does not block the mutation - the intent is
        // still recorded, just without the old values.
        $old = [];

        try {
            $info = $context->gateway()->getMailboxInfo($email);
            if (null !== $info) {
                if (isset($properties['description'])) {
                    $old['description'] = $info['description'];
                }
                if (isset($properties['outgoing-messages-mbox-limit'])) {
                    $old['outgoing_messages_mbox_limit'] = $info['outgoing_messages_mbox_limit'];
                }
                if (isset($properties['antivir'])) {
                    $old['antivir'] = $info['antivir'];
                }
                if (null !== $quotaMiB) {
                    $old['quota_mib'] = $info['mailbox_quota'] / 1048576;
                }
            }
        } catch (\Throwable) {
            // Ignore: the mutation below will surface connectivity problems.
        }

        $new = $properties;
        if (null !== $quotaMiB) {
            $new['quota_mib'] = $quotaMiB;
        }
        $details = ['new' => $new];
        if ([] !== $old) {
            $details['old'] = $old;
        }

        $logId = $context->syncLogRepository()->logPending('mail_address', $email, 'set', $details);

        try {
            if ([] !== $properties) {
                $context->gateway()->setMailboxProperties($email, $properties);
            }

            if (null !== $quotaMiB) {
                $context->gateway()->setMailboxQuota($email, $quotaMiB * 1048576);
            }

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Mailbox for <info>%s</info> would be updated (dry-run).', $email)
                : sprintf('Mailbox for <info>%s</info> updated.', $email));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
