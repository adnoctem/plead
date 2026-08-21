<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use App\Rule\GroupRule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:group:set', description: 'Replace the full recipient list of a mail group (--recipients or --rule)')]
final class GroupSetCommand extends AbstractGroupCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Group email address, e.g. all@company.com')
            ->addOption('recipients', null, InputOption::VALUE_REQUIRED, 'Comma-separated recipient email addresses')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, 'PCRE pattern of addresses to EXCLUDE from the domain (mutually exclusive with --recipients)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $recipientsOption = (string) $input->getOption('recipients');
        $ruleOption = (string) $input->getOption('rule');

        if ('' !== $ruleOption && '' !== $recipientsOption) {
            $output->writeln('<error>--rule and --recipients are mutually exclusive.</error>');

            return self::FAILURE;
        }

        if ('' !== $ruleOption) {
            return $this->applyRule($email, $ruleOption, null, $output);
        }

        if ('' !== $recipientsOption) {
            try {
                $recipients = $this->parseRecipients($recipientsOption);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                return self::FAILURE;
            }

            return $this->applyRecipients($email, $recipients, $output);
        }

        // Neither option: fall back to the configured mail.group definition.
        $entry = $this->configGroupEntry($email);
        if (null === $entry) {
            $output->writeln('<error>Provide --recipients or --rule, or configure a mail.group entry for '.$email.' in the config.</error>');

            return self::FAILURE;
        }

        if (null !== ($entry['pattern'] ?? null)) {
            return $this->applyRule($email, (string) $entry['pattern'], $entry['domain'] ?? null, $output);
        }

        return $this->applyRecipients($email, $entry['recipients'], $output);
    }

    private function applyRule(string $email, string $pattern, ?string $domain, OutputInterface $output): int
    {
        try {
            $rule = GroupRule::fromConfigEntry([
                'address' => $email,
                'domain' => $domain,
                'pattern' => $pattern,
                'recipients' => [],
            ]);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $context = $this->context();

        // A rule-derived list is authoritative: no adoption from Plesk, so
        // recipients that do not match the rule are purged on the first run.
        if (!$context->groupRuleEngine()->apply($rule)) {
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
