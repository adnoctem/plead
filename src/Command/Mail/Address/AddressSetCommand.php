<?php

declare(strict_types=1);

namespace App\Command\Mail\Address;

use App\Command\Mail\AbstractMailCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:address:set', description: 'Set properties of an address (--description)')]
final class AddressSetCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address to update')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'New description for the address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $context = $this->context();

        if (null === $input->getOption('description')) {
            $output->writeln('<error>Provide --description.</error>');

            return self::FAILURE;
        }

        $gateway = $context->gateway();
        $logId = $context->syncLogRepository()->logPending('mail_address', $email, 'set');

        try {
            $gateway->setMailboxDescription($email, (string) $input->getOption('description'));

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Mailbox for <info>%s</info> would be updated (dry-run).', $email)
                : sprintf('Mailbox for <info>%s</info> updated.', $email));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
