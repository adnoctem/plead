<?php

declare(strict_types=1);

namespace App\Command\Server\Service;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:service:status', description: 'Show server service states (all, or one service by id)')]
final class ServiceStatusCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('service', InputArgument::OPTIONAL, 'Service id to inspect (e.g. web, mail, dns)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $services = $this->context()->gateway()->listServiceStates();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $serviceId = $input->getArgument('service');
        if (null !== $serviceId) {
            foreach ($services as $service) {
                if ($service['id'] === $serviceId) {
                    $output->writeln(sprintf(
                        '%-12s %-8s %s',
                        $service['id'],
                        $service['state'],
                        $service['title'],
                    ));
                    if ('' !== $service['error']) {
                        $output->writeln(sprintf('  error: %s', $service['error']));
                    }

                    return self::SUCCESS;
                }
            }

            $output->writeln(sprintf('No service with id <info>%s</info> on the server.', $serviceId));

            return self::SUCCESS;
        }

        if ([] === $services) {
            $output->writeln('No service states returned by the server.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-12s %-8s %s', 'Service', 'State', 'Title'));
        foreach ($services as $service) {
            $line = sprintf(
                '%-12s %-8s %s',
                $service['id'],
                $service['state'],
                $service['title'],
            );
            if ('' !== $service['error']) {
                $line .= sprintf('  [%s]', $service['error']);
            }
            $output->writeln($line);
        }

        return self::SUCCESS;
    }
}
