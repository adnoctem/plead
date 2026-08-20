<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:set', description: 'Set properties of a domain (--description)')]
final class DomainSetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name, e.g. delta4x4.net')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'New description for the domain')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Domain status: enabled|disabled (not yet supported)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');
        $context = $this->context();

        if (null !== $input->getOption('status')) {
            $status = strtolower((string) $input->getOption('status'));
            if (!in_array($status, ['enabled', 'disabled'], true)) {
                $output->writeln(sprintf(
                    '<error>Invalid value for --status: "%s". Use enabled or disabled.</error>',
                    $input->getOption('status'),
                ));

                return self::FAILURE;
            }

            // TODO: implement status toggling once the webspace status packet
            // (status 0/16 via gen_setup) has been validated against a live
            // server. The flag stays in the API surface so callers do not
            // break when it lands.
            $output->writeln('<error>--status is not supported yet.</error>');

            return self::FAILURE;
        }

        if (null === $input->getOption('description')) {
            $output->writeln('<error>Provide --description.</error>');

            return self::FAILURE;
        }

        $gateway = $context->gateway();
        $logId = $context->syncLogRepository()->logPending('domain', $domain, 'set');

        try {
            $gateway->updateDomain($domain, (string) $input->getOption('description'));

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Domain <info>%s</info> would be updated (dry-run).', $domain)
                : sprintf('Domain <info>%s</info> updated.', $domain));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
