<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:password', description: 'Set or rotate the mailbox password of an address')]
final class AddressPasswordCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address whose password to change')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'New password; mutually exclusive with --generate')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a random password and print it once')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $context = $this->context();

        $explicit = (string) $input->getOption('password');
        $generate = (bool) $input->getOption('generate');

        if ($generate && '' !== $explicit) {
            $output->writeln('<error>--password and --generate are mutually exclusive.</error>');

            return self::FAILURE;
        }

        if ($generate) {
            // 24 hex chars via random_bytes - printed once, never stored.
            $explicit = bin2hex(random_bytes(12));
        } elseif ('' === $explicit) {
            $output->writeln('<error>Provide --password or use --generate.</error>');

            return self::FAILURE;
        }

        $gateway = $context->gateway();
        $logId = $context->syncLogRepository()->logPending('mail_address', $email, 'password');

        try {
            $gateway->setMailboxPassword($email, $explicit);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            if ($context->dryRun()) {
                $output->writeln(sprintf('Password for <info>%s</info> would be changed (dry-run).', $email));

                return self::SUCCESS;
            }

            $output->writeln(sprintf('Password for <info>%s</info> changed.', $email));
            if ($generate) {
                $output->writeln(sprintf('Generated password: <info>%s</info> (shown once)', $explicit));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
