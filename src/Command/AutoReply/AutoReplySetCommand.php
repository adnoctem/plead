<?php

declare(strict_types=1);

namespace App\Command\AutoReply;

use App\Command\AbstractPleadCommand;
use App\Util\DateNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auto-reply:set', description: 'Schedule an auto-reply for an email address')]
final class AutoReplySetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address, e.g. user@company.com')
            ->addOption('message-file', null, InputOption::VALUE_REQUIRED, 'File containing the auto-reply message (rendered as a Twig template)')
            ->addOption('start-date', null, InputOption::VALUE_REQUIRED, 'When the auto-reply becomes active (default: now). Any date string is accepted and normalized to ISO 8601 with offset.')
            ->addOption('end-date', null, InputOption::VALUE_REQUIRED, 'When the auto-reply stops. Plesk handles the turn-off natively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $context = $this->context();

        $messageFile = (string) $input->getOption('message-file');
        if ('' === $messageFile || !is_file($messageFile) || !is_readable($messageFile)) {
            $output->writeln(sprintf('<error>Cannot read message file: %s</error>', $messageFile));

            return self::FAILURE;
        }

        $startDate = DateNormalizer::normalize((string) ($input->getOption('start-date') ?: DateNormalizer::now()));
        $endDate = DateNormalizer::normalize((string) $input->getOption('end-date'));
        if (new \DateTimeImmutable($endDate) <= new \DateTimeImmutable($startDate)) {
            $output->writeln('<error>end-date must be after start-date.</error>');

            return self::FAILURE;
        }

        $message = $context->renderer()->render([
            'message' => (string) file_get_contents($messageFile),
            'date' => new \DateTimeImmutable(),
        ]);

        $context->autoReplyRepository()->upsert($email, $message, $startDate, $endDate);
        $context->syncLogRepository()->log('auto_reply', $email, 'schedule', 'ok');

        if (new \DateTimeImmutable($startDate) <= new \DateTimeImmutable()) {
            $context->reconciler()->reconcile($email);

            if ($context->dryRun()) {
                $output->writeln(sprintf('Auto-reply for <info>%s</info> would be applied (dry-run).', $email));

                return self::SUCCESS;
            }

            $row = $context->autoReplyRepository()->find($email);
            if (null === $row || null === $row['applied_at']) {
                $output->writeln(sprintf('<error>Auto-reply for %s was scheduled but could not be applied to Plesk.</error>', $email));
                $output->writeln('Check the log file or run `auto-reply:get` to inspect the current state.');

                return self::FAILURE;
            }

            $output->writeln(sprintf('Auto-reply for <info>%s</info> applied.', $email));

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            'Auto-reply for <info>%s</info> scheduled; will become active at <info>%s</info>.',
            $email,
            $startDate,
        ));

        return self::SUCCESS;
    }
}
