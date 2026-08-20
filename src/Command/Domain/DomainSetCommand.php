<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:set', description: 'Set properties of a domain (--description, --status)')]
final class DomainSetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name, e.g. delta4x4.net')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'New description for the domain')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Domain status: enabled|disabled (0/16 via gen_setup, validated live)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');
        $context = $this->context();

        $status = null;
        if (null !== $input->getOption('status')) {
            $status = strtolower((string) $input->getOption('status'));
            if (!in_array($status, ['enabled', 'disabled'], true)) {
                $output->writeln(sprintf(
                    '<error>Invalid value for --status: "%s". Use enabled or disabled.</error>',
                    $input->getOption('status'),
                ));

                return self::FAILURE;
            }
        }

        if (null === $input->getOption('description') && null === $status) {
            $output->writeln('<error>Provide --description and/or --status.</error>');

            return self::FAILURE;
        }

        $gateway = $context->gateway();

        // Audit the change with the ORIGINAL values: read the current state
        // first. A read failure does not block the mutation.
        $old = [];

        try {
            $info = $gateway->getDomain($domain);
            if (null !== $info) {
                if (null !== $input->getOption('description')) {
                    $old['description'] = (string) ($info['description'] ?? '');
                }
                if (null !== $status) {
                    $old['status'] = '0' === (string) ($info['status'] ?? '0') ? 'enabled' : 'disabled';
                }
            }
        } catch (\Throwable) {
            // Ignore: the mutation below will surface connectivity problems.
        }

        $new = [];
        if (null !== $input->getOption('description')) {
            $new['description'] = (string) $input->getOption('description');
        }
        if (null !== $status) {
            $new['status'] = $status;
        }
        $details = ['new' => $new];
        if ([] !== $old) {
            $details['old'] = $old;
        }

        $logId = $context->syncLogRepository()->logPending('domain', $domain, 'set', $details);

        try {
            if (null !== $input->getOption('description')) {
                $gateway->updateDomain($domain, (string) $input->getOption('description'));
            }

            if (null !== $status) {
                $gateway->setDomainStatus($domain, 'enabled' === $status ? 0 : 16);
            }

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Domain <info>%s</info> would be updated (dry-run).', $domain)
                : sprintf('Domain <info>%s</info> updated.', $domain));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:'.$e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
