<?php

declare(strict_types=1);

namespace App\Command\Mail\Autoresponder;

use App\Command\Mail\AbstractMailCommand;
use App\Config\ConfigFile;
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
            ->addOption('data-file', null, InputOption::VALUE_REQUIRED, 'JSON or YAML file with template data (e.g. firstname, phoneNumber); the schedule dates are injected automatically')
            ->addOption('start-date', null, InputOption::VALUE_REQUIRED, 'When the auto-reply becomes active (default: now). Any date string is accepted and normalized to ISO 8601 with offset.')
            ->addOption('end-date', null, InputOption::VALUE_REQUIRED, 'When the auto-reply stops. Plesk handles the turn-off natively.')
            ->addOption('inclusive', null, InputOption::VALUE_NEGATABLE, 'Whether the end date counts as vacation: --inclusive/--no-inclusive (default: false). Authoritative - overrides the data file.')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Render and print the message with the configured template, data file and dates; records nothing and touches neither the database nor Plesk')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $context = $this->context();

        if ($input->getOption('preview')) {
            if (!$this->mergeConfigEntry($input, $output, $email)) {
                return self::FAILURE;
            }

            return $this->preview($input, $output, $email);
        }

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
        } else {
            if (!$this->mergeConfigEntry($input, $output, $email)) {
                return self::FAILURE;
            }
        }

        $result = $enabled ? $this->enable($input, $output, $email) : $this->disable($output, $email);

        if (self::SUCCESS === $result && $context->writeConfig()) {
            $result = $enabled
                ? $this->persistDefinition($input, $email, $output)
                : $this->removeDefinition($email, $output);
        }

        return $result;
    }

    /**
     * The merged mail.autoresponder entry defining an address, if any.
     *
     * @return null|array<string, mixed>
     */
    private function configAutoresponderEntry(string $email): ?array
    {
        $email = strtolower($email);
        foreach ($this->context()->config()['mail']['autoresponder'] ?? [] as $entry) {
            if (strtolower((string) ($entry['address'] ?? '')) === $email) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Fill CLI options from the configured mail.autoresponder entry: with no
     * options at all the entry defines everything; with a partial CLI run the
     * entry fills the missing pieces (message/data file and dates). Returns
     * false when nothing at all could be resolved for the address.
     */
    private function mergeConfigEntry(InputInterface $input, OutputInterface $output, string $email): bool
    {
        $entry = $this->configAutoresponderEntry($email);
        if (null === $entry) {
            $hasOptions = null !== $input->getOption('message-file')
                || null !== $input->getOption('data-file')
                || null !== $input->getOption('start-date')
                || null !== $input->getOption('end-date');
            if ($hasOptions) {
                return true;
            }

            $output->writeln(sprintf(
                '<error>Provide --message-file (and dates), or configure a mail.autoresponder entry for %s in the config.</error>',
                $email,
            ));

            return false;
        }

        if (null === $input->getOption('message-file')) {
            $input->setOption('message-file', $entry['message_file']);
        }
        if (null === $input->getOption('data-file') && null !== ($entry['data_file'] ?? null)) {
            $input->setOption('data-file', $entry['data_file']);
        }
        if (null === $input->getOption('start-date') && null !== ($entry['start_date'] ?? null)) {
            $input->setOption('start-date', DateNormalizer::coerce($entry['start_date']));
        }
        if (null === $input->getOption('end-date')) {
            $input->setOption('end-date', DateNormalizer::coerce($entry['end_date']));
        }
        if (null === $input->getOption('inclusive') && null !== ($entry['inclusive'] ?? null)) {
            $input->setOption('inclusive', (bool) $entry['inclusive']);
        }

        return true;
    }

    private function persistDefinition(InputInterface $input, string $email, OutputInterface $output): int
    {
        $entry = [
            'address' => $email,
            'message_file' => (string) $input->getOption('message-file'),
            'start_date' => DateNormalizer::normalize((string) ($input->getOption('start-date') ?: DateNormalizer::now())),
            'end_date' => (string) $input->getOption('end-date'),
        ];
        if (null !== $input->getOption('data-file')) {
            $entry['data_file'] = (string) $input->getOption('data-file');
        }
        if (null !== $input->getOption('inclusive')) {
            $entry['inclusive'] = (bool) $input->getOption('inclusive');
        }
        ksort($entry);

        $target = ConfigFile::targetFile($this->context()->paths->configPaths());

        try {
            ConfigFile::upsertMailAutoresponder($target, $email, $entry);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Unable to write the config file: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('mail.autoresponder entry for <info>%s</info> written to %s', $email, $target));

        return self::SUCCESS;
    }

    private function removeDefinition(string $email, OutputInterface $output): int
    {
        $target = ConfigFile::targetFile($this->context()->paths->configPaths());

        try {
            ConfigFile::removeMailAutoresponder($target, $email);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Unable to write the config file: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('mail.autoresponder entry for <info>%s</info> removed from %s', $email, $target));

        return self::SUCCESS;
    }

    /**
     * Render and print the auto-reply exactly as it would be stored and
     * pushed (same template, data file and schedule), without recording an
     * intent or touching the server - a pure dry check.
     */
    private function preview(InputInterface $input, OutputInterface $output, string $email): int
    {
        $context = $this->context();

        $messageFile = (string) $input->getOption('message-file');
        if ('' === $messageFile || !is_file($messageFile) || !is_readable($messageFile)) {
            $output->writeln(sprintf('<error>Cannot read message file: %s</error>', $messageFile));

            return self::FAILURE;
        }

        $dataFile = $input->getOption('data-file');
        if (null !== $dataFile && (!is_file($dataFile) || !is_readable($dataFile))) {
            $output->writeln(sprintf('<error>Cannot read data file: %s</error>', $dataFile));

            return self::FAILURE;
        }

        try {
            [$startDate, $endDate] = $this->resolveDates($input);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        try {
            $message = $context->renderer()->renderMessage(
                $messageFile,
                $dataFile,
                $startDate,
                $endDate,
                $input->getOption('inclusive'),
            );
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Unable to render the auto-reply message: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln($message);

        return self::SUCCESS;
    }

    /**
     * Normalize the CLI dates and enforce the ordering; throws on empty or
     * invalid values.
     *
     * @return array{0: string, 1: string} normalized start date, end date
     */
    private function resolveDates(InputInterface $input): array
    {
        $startDate = DateNormalizer::normalize((string) ($input->getOption('start-date') ?: DateNormalizer::now()));
        $endDate = DateNormalizer::normalize((string) $input->getOption('end-date'));
        if (new \DateTimeImmutable($endDate) <= new \DateTimeImmutable($startDate)) {
            throw new \InvalidArgumentException('end-date must be after start-date.');
        }

        return [$startDate, $endDate];
    }

    private function enable(InputInterface $input, OutputInterface $output, string $email): int
    {
        $context = $this->context();

        $messageFile = (string) $input->getOption('message-file');
        if ('' === $messageFile || !is_file($messageFile) || !is_readable($messageFile)) {
            $output->writeln(sprintf('<error>Cannot read message file: %s</error>', $messageFile));

            return self::FAILURE;
        }

        $dataFile = $input->getOption('data-file');
        if (null !== $dataFile && (!is_file($dataFile) || !is_readable($dataFile))) {
            $output->writeln(sprintf('<error>Cannot read data file: %s</error>', $dataFile));

            return self::FAILURE;
        }

        $startDate = DateNormalizer::normalize((string) ($input->getOption('start-date') ?: DateNormalizer::now()));
        $endDate = DateNormalizer::normalize((string) $input->getOption('end-date'));
        if (new \DateTimeImmutable($endDate) <= new \DateTimeImmutable($startDate)) {
            $output->writeln('<error>end-date must be after start-date.</error>');

            return self::FAILURE;
        }

        try {
            $message = $context->renderer()->renderMessage(
                $messageFile,
                $dataFile,
                $startDate,
                $endDate,
                $input->getOption('inclusive'),
            );
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Unable to render the auto-reply message: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

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
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());

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
