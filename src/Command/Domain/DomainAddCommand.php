<?php

declare(strict_types=1);

namespace App\Command\Domain;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:add', description: 'Create a domain (site) on the server')]
final class DomainAddCommand extends AbstractPleadCommand
{
    private const HOSTING_TYPES = [
        'virtual-host' => 'vrt_hst',
        'forwarding' => 'std_fwd',
        'frame-forwarding' => 'frm_fwd',
        'none' => 'none',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain name to create, e.g. new.domain.com')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Hosting type: virtual-host|forwarding|frame-forwarding|none')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Webspace (subscription) the domain belongs to; defaults to the administrator')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Domain description')
            ->addOption('dest-url', null, InputOption::VALUE_REQUIRED, 'Target URL for forwarding/frame-forwarding types')
            ->addOption('property', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Virtual-hosting property, name:value (repeatable), e.g. --property=ftp_login:user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domain = (string) $input->getArgument('domain');
        $type = $input->getOption('type') ? strtolower((string) $input->getOption('type')) : null;

        if (null === $type || !isset(self::HOSTING_TYPES[$type])) {
            $output->writeln(sprintf(
                '<error>Invalid or missing --type: "%s". Use virtual-host, forwarding, frame-forwarding or none.</error>',
                (string) $type,
            ));

            return self::FAILURE;
        }

        if (in_array($type, ['forwarding', 'frame-forwarding'], true) && null === $input->getOption('dest-url')) {
            $output->writeln('<error>Provide --dest-url for forwarding/frame-forwarding types.</error>');

            return self::FAILURE;
        }

        $properties = [];
        foreach ((array) $input->getOption('property') as $property) {
            $parts = explode(':', $property, 2);
            if (2 !== count($parts) || '' === $parts[0]) {
                $output->writeln(sprintf('<error>Invalid --property "%s": use name:value.</error>', $property));

                return self::FAILURE;
            }
            $properties[$parts[0]] = $parts[1];
        }

        $context = $this->context();
        $htype = self::HOSTING_TYPES[$type];

        // Audit first.
        $details = [
            'domain' => $domain,
            'type' => $type,
        ];
        if (null !== $input->getOption('parent')) {
            $details['parent'] = (string) $input->getOption('parent');
        }
        $logId = $context->syncLogRepository()->logPending('domain', $domain, 'add', $details);

        try {
            $context->gateway()->addSite(
                $domain,
                $htype,
                $input->getOption('parent') ? (string) $input->getOption('parent') : null,
                $input->getOption('description') ? (string) $input->getOption('description') : null,
                $properties,
                $input->getOption('dest-url') ? (string) $input->getOption('dest-url') : null,
            );

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Domain <info>%s</info> would be created (dry-run).', $domain)
                : sprintf('Domain <info>%s</info> created.', $domain));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
