<?php

declare(strict_types=1);

namespace App\Command\Mail\Autoresponder;

use App\Command\Mail\AbstractMailCommand;
use App\Util\DateNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:autoresponder:set', description: 'Enable, schedule or disable the auto-reply of an address')]
final class AutoresponderSetCommand extends AbstractMailCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address, e.g. user@company.com')
            ->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'Whether the auto-reply should be enabled: true|false|yes|no|1|0 (default: true)')
            ->addOption('message-file', null, InputOption::VALUE_REQUIRED, 'File containing the auto-reply message (rendered as a Twig template)')
            ->addOption('start-date', null, InputOption::VALUE_REQUIRED, 'When the auto-reply becomes active (default: now). Any date string is accepted and normalized to ISO 8601 with offset.')
            ->addOption('end-date', null, InputOption::VALUE_REQUIRED, 'When the auto-reply stops. Plesk handles the turn-off natively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $context = $this->context();

        $enabled = true;
        if (null !== $input->getOption('enabled')) {
            $enabled = $this->parseEnabled((string) $input->getOption('enabled'));
            if (null === $enabled) {
                $output->writeln(sprintf(
                    '<error>Invalid value for --enabled: "%s". Use true or false.</error>',
                    $input->getOption('enabled'),
                ));

                return self::FAILURE;
            }
        }

        return $enabled ? $this->enable($input, $output, $email) : $this->disable($output, $email);
    }

    private function enable(InputInterface $input, OutputInterface $output, string $email): int
    {
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

        // Audit first: the upsert below is the intent; the reconciler decides
        // whether it is applied now or picked up by the watcher.
        $logId = $context->syncLogRepository()->logPending('auto_reply', $email, 'set');

        $context->autoReplyRepository()->upsert($email, $message, $startDate, $endDate);

        if (new \DateTimeImmutable($startDate) <= new \DateTimeImmutable()) {
            $context->reconciler()->reconcile($email);

            if ($context->dryRun()) {
                $output->writeln(sprintf('Auto-reply for <info>%s</info> would be applied (dry-run).', $email));
                $context->syncLogRepository()->resolve($logId, 'dry-run');

                return self::SUCCESS;
            }

            $row = $context->autoReplyRepository()->find($email);
            if (null !== $row && '1' === (string) $row['reconciled']) {
                $output->writeln(sprintf('Auto-reply for <info>%s</info> applied.', $email));
                $context->syncLogRepository()->resolve($logId, 'ok');

                return self::SUCCESS;
            }

            $output->writeln(sprintf(
                '<comment>Auto-reply for %s is scheduled but could not be applied to Plesk yet; mail:autoresponder:watch will retry it.</comment>',
                $email,
            ));
            $context->syncLogRepository()->resolve($logId, 'ok');

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            'Auto-reply for <info>%s</info> scheduled; will become active at <info>%s</info>.',
            $email,
            $startDate,
        ));
        $context->syncLogRepository()->resolve($logId, 'ok');

        return self::SUCCESS;
    }

    private function disable(OutputInterface $output, string $email): int
    {
        $context = $this->context();

        // DB first: record the intent. The row is deliberately kept - the
        // status column and sync log form the audit trail - and the entry is
        // flagged dirty so the watcher can retry a failed push.
        $row = $context->autoReplyRepository()->find($email);
        if (null !== $row) {
            $context->autoReplyRepository()->disable($email);
        }

        $logId = $context->syncLogRepository()->logPending('auto_reply', $email, 'disable');

        try {
            $context->gateway()->disableAutoresponder($email);
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());

            if (null !== $row) {
                $output->writeln(sprintf(
                    '<comment>Could not disable the auto-reply for %s right now; the local entry stays pending and mail:autoresponder:watch will retry it.</comment>',
                    $email,
                ));

                return self::SUCCESS;
            }

            $output->writeln(sprintf('<error>Could not disable the auto-reply for %s: %s</error>', $email, $e->getMessage()));

            return self::FAILURE;
        }

        if ($context->dryRun()) {
            $context->syncLogRepository()->resolve($logId, 'dry-run');
            $output->writeln(sprintf('Auto-reply for <info>%s</info> would be disabled (dry-run).', $email));

            return self::SUCCESS;
        }

        if (null !== $row) {
            $context->autoReplyRepository()->markReconciled($email, DateNormalizer::now());
        }

        $context->syncLogRepository()->resolve($logId, 'ok');
        $output->writeln(sprintf('Auto-reply for <info>%s</info> disabled.', $email));

        return self::SUCCESS;
    }

    private function parseEnabled(string $value): ?bool
    {
        return match (strtolower($value)) {
            'true', 'yes', '1', 'on' => true,
            'false', 'no', '0', 'off' => false,
            default => null,
        };
    }
}
