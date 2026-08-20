<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:traffic:set', description: 'Manually record traffic counters for one domain and date')]
final class DomainTrafficSetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name, e.g. delta4x4.net')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Traffic date, YYYY-MM-DD')
            ->addOption('smtp-in', null, InputOption::VALUE_REQUIRED, 'SMTP inbound bytes')
            ->addOption('smtp-out', null, InputOption::VALUE_REQUIRED, 'SMTP outbound bytes')
            ->addOption('pop3-imap-in', null, InputOption::VALUE_REQUIRED, 'POP3/IMAP inbound bytes')
            ->addOption('pop3-imap-out', null, InputOption::VALUE_REQUIRED, 'POP3/IMAP outbound bytes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');

        $date = $input->getOption('date');
        if (null === $date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $output->writeln(sprintf('<error>Provide --date in YYYY-MM-DD format%s.</error>', null === $date ? '' : ', got: '.$date));

            return self::FAILURE;
        }

        $counters = [];
        foreach (['smtp-in' => 'smtp_in', 'smtp-out' => 'smtp_out', 'pop3-imap-in' => 'pop3_imap_in', 'pop3-imap-out' => 'pop3_imap_out'] as $option => $element) {
            $value = $input->getOption($option);
            if (null !== $value) {
                if (!preg_match('/^\d+$/', (string) $value)) {
                    $output->writeln(sprintf('<error>--%s must be a non-negative integer, got: %s</error>', $option, $value));

                    return self::FAILURE;
                }
                $counters[$element] = (int) $value;
            }
        }

        if ([] === $counters) {
            $output->writeln('<error>Provide at least one counter (--smtp-in, --smtp-out, --pop3-imap-in, --pop3-imap-out).</error>');

            return self::FAILURE;
        }

        $context = $this->context();

        // Audit first.
        $details = ['date' => (string) $date, 'counters' => $counters];
        $logId = $context->syncLogRepository()->logPending('domain', $domain, 'traffic:set', $details);

        try {
            $context->gateway()->setSiteTraffic($domain, (string) $date, $counters);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Traffic for <info>%s</info> on %s would be recorded (dry-run).', $domain, $date)
                : sprintf('Traffic for <info>%s</info> on %s recorded.', $domain, $date));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
