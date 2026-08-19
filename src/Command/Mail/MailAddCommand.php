<?php

declare(strict_types=1);

namespace App\Command\Mail;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:add', description: 'Add a recipient to a mail group')]
final class MailAddCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com')
            ->addArgument('recipient', InputArgument::REQUIRED, 'Recipient email address to add');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $recipient = (string) $input->getArgument('recipient');

        if (!str_contains($recipient, '@')) {
            $output->writeln(sprintf('<error>Not a valid recipient email address: %s</error>', $recipient));

            return self::FAILURE;
        }

        $context = $this->context();
        $this->adoptIfNew($email);
        $context->mailGroupRepository()->upsertActive($email, $recipient);
        $context->syncLogRepository()->log('mail_group', $email, 'add', 'ok');

        $this->applyAndReport($output, $email);

        return self::SUCCESS;
    }
}
