<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use App\Config\ConfigFile;
use App\Rule\GroupRule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:group:set', description: 'Replace the full recipient list of a mail group (--recipients, --rule, or both)')]
final class GroupSetCommand extends AbstractGroupCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com')
            ->addOption('recipients', null, InputOption::VALUE_REQUIRED, 'Comma-separated recipient email addresses (appended to the rule-filtered list when --rule is also given)')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'PCRE pattern of addresses to EXCLUDE from the domain; combine with --recipients to append manual addresses')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $ruleOption = (string) $input->getOption('rule');
        $recipientsOption = (string) $input->getOption('recipients');

        $recipients = [];
        if ('' !== $recipientsOption) {
            try {
                $recipients = $this->parseRecipients($recipientsOption);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                return self::FAILURE;
            }
        }

        // Configured mail.group definitions are the fallback; explicit flags
        // override the corresponding part of the entry.
        $entry = $this->configGroupEntry($email);
        $pattern = '' !== $ruleOption ? $ruleOption : (null !== ($entry['pattern'] ?? null) ? (string) $entry['pattern'] : null);
        if ('' === $recipientsOption && null !== $entry) {
            $recipients = $entry['recipients'];
        }

        if (null === $pattern && [] === $recipients) {
            $output->writeln('<error>Provide --recipients or --rule, or configure a mail.group entry for '.$email.' in the config.</error>');

            return self::FAILURE;
        }

        $result = null !== $pattern
            ? $this->applyRule($email, $pattern, $entry['domain'] ?? null, $recipients, $output)
            : $this->applyRecipients($email, $recipients, $output);

        if (self::SUCCESS === $result && $this->context()->writeConfig()) {
            $result = $this->persistDefinition($email, $pattern, $recipients, $output);
        }

        return $result;
    }

    /**
     * Persist the applied definition to the config file so the watcher keeps
     * maintaining the list (--write-config).
     *
     * @param string[] $recipients
     */
    private function persistDefinition(string $email, ?string $pattern, array $recipients, OutputInterface $output): int
    {
        $entry = ['address' => $email];
        if (null !== $pattern) {
            $entry['pattern'] = $pattern;
        }
        if ([] !== $recipients) {
            $entry['recipients'] = $recipients;
        }

        $target = ConfigFile::targetFile($this->context()->paths->configPaths());

        try {
            ConfigFile::upsertMailGroup($target, $email, $entry);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Unable to write the config file: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('mail.group entry for <info>%s</info> written to %s', $email, $target));

        return self::SUCCESS;
    }

    /** @param string[] $recipients */
    private function applyRule(string $email, string $pattern, ?string $domain, array $recipients, OutputInterface $output): int
    {
        try {
            $rule = GroupRule::fromConfigEntry([
                'address' => $email,
                'domain' => $domain,
                'pattern' => $pattern,
                'recipients' => $recipients,
            ]);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $context = $this->context();

        // A rule-derived list is authoritative: no adoption from Plesk, so
        // recipients that do not match the rule are purged on the first run.
        if (!$context->groupRuleEngine()->apply($rule)) {
            // The local desired state already matches the rule, but Plesk may
            // not have confirmed it yet (intents left behind by a --dry-run or
            // a failed push). Push them now instead of reporting a false no-op.
            if (in_array($email, $context->mailGroupRepository()->unreconciledLists(), true)) {
                $this->applyAndReport($output, $email);

                return self::SUCCESS;
            }

            $output->writeln(sprintf('Recipients for <info>%s</info> already match the rule (no changes).', $email));

            return self::SUCCESS;
        }

        $this->applyAndReport($output, $email);

        return self::SUCCESS;
    }

    /** @param string[] $recipients */
    private function applyRecipients(string $email, array $recipients, OutputInterface $output): int
    {
        if ([] === $recipients) {
            $output->writeln('<error>--recipients must contain at least one address.</error>');

            return self::FAILURE;
        }

        $context = $this->context();
        $repository = $context->mailGroupRepository();

        try {
            $this->adoptIfNew($email);
        } catch (\Throwable $e) {
            // Seeding from Plesk failed; aborting keeps the local state free
            // of blind mutations (read-before-write).
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        // Audit first: the local mutation below is the intent.
        $logId = $context->syncLogRepository()->logPending('mail_group', $email, 'set', ['recipients' => $recipients]);

        foreach ($recipients as $recipient) {
            $repository->upsertActive($email, $recipient);
        }
        foreach ($repository->activeRecipients($email) as $existing) {
            if (!in_array($existing, $recipients, true)) {
                $repository->remove($email, $existing);
            }
        }

        $this->applyAndReport($output, $email);
        $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

        return self::SUCCESS;
    }
}
