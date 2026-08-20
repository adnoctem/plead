<?php

declare(strict_types=1);

namespace App\Command\Mail\Alias;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:alias:set', description: 'Replace the full alias list of a mailbox')]
final class AliasSetCommand extends AbstractAliasCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Mailbox email address, e.g. user@company.com')
            ->addOption('aliases', null, InputOption::VALUE_REQUIRED, 'Comma-separated alias email addresses');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');

        try {
            $aliases = array_map(
                fn (string $alias): string => $this->normalizeAlias($email, $alias),
                $this->parseAliases((string) $input->getOption('aliases')),
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $aliases) {
            $output->writeln('<error>--aliases must contain at least one address.</error>');

            return self::FAILURE;
        }

        $context = $this->context();
        $repository = $context->mailAliasRepository();
        try {
            $this->adoptIfNew($email);
        } catch (\Throwable $e) {
            // Seeding from Plesk failed; aborting keeps the local state free
            // of blind mutations (read-before-write).
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        // Audit first: the local mutation below is the intent.
        $logId = $context->syncLogRepository()->logPending('mail_alias', $email, 'set', ['aliases' => $aliases]);

        foreach ($aliases as $alias) {
            $repository->upsertActive($email, $alias);
        }
        foreach ($repository->activeAliases($email) as $existing) {
            if (!in_array($existing, $aliases, true)) {
                $repository->remove($email, $existing);
            }
        }

        $this->applyAndReport($output, $email);
        $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

        return self::SUCCESS;
    }
}
