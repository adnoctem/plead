<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:get', description: 'Show everything plead knows about an address (live Plesk state; --local shows local records)')]
final class AddressGetCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to inspect')
            ->addLocalOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');

        return $this->isLocal($input)
            ? $this->showLocal($output, $email)
            : $this->showLive($output, $email);
    }

    private function showLocal(OutputInterface $output, string $email): int
    {
        $context = $this->context();
        $memberships = $context->mailGroupRepository()->listsOf($email);
        $autoReply = $context->autoReplyRepository()->find($email);

        $output->writeln(sprintf('Address: %s', $email));

        if ([] === $memberships && null === $autoReply) {
            $output->writeln('Not known to plead yet.');

            return self::SUCCESS;
        }

        if ([] !== $memberships) {
            $output->writeln('Group memberships:');
            foreach ($memberships as $membership) {
                $marker = null !== $membership['removed_at']
                    ? ' (removed)'
                    : ('0' === (string) $membership['reconciled'] ? ' (pending)' : '');
                $output->writeln('  - ' . $membership['list_email'] . $marker);
            }
        }

        if (null !== $autoReply) {
            $output->writeln(sprintf('Auto-reply:   status=%s, %s', $autoReply['status'], '1' === (string) $autoReply['reconciled'] ? 'reconciled' : 'pending'));
            $output->writeln(sprintf('  window:      %s -> %s', $autoReply['start_date'], $autoReply['end_date']));
        }

        return self::SUCCESS;
    }

    private function showLive(OutputInterface $output, string $email): int
    {
        try {
            $info = $this->context()->gateway()->getMailboxInfo($email);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if (null === $info) {
            $output->writeln(sprintf('No mail address <info>%s</info> found on the server.', $email));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('Address:       %s', $email));
        $output->writeln(sprintf('Description:   %s', $info['description'] ?: '(none)'));
        $output->writeln(sprintf('Mailbox:       %s', $info['mailbox_enabled'] ? 'enabled' : 'disabled'));

        if (!empty($info['mailbox_quota'])) {
            $usage = !empty($info['mailbox_usage'])
                ? sprintf(' (used %s)', self::bytesToMiB((int) $info['mailbox_usage']))
                : '';
            $output->writeln(sprintf('Quota:         %s%s', self::bytesToMiB((int) $info['mailbox_quota']), $usage));
        }

        $output->writeln('Forwarding:');
        if ([] === $info['forwarding']) {
            $output->writeln('  (none)');
        } else {
            foreach ($info['forwarding'] as $address) {
                $output->writeln('  - ' . $address);
            }
        }

        $output->writeln(sprintf('Auto-reply:    %s', $info['autoresponder_enabled'] ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }

    private static function bytesToMiB(int $bytes): string
    {
        return sprintf('%.1f MiB', $bytes / 1048576);
    }
}
