<?php

declare(strict_types=1);

namespace App\Command\Server;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:info', description: 'Show server identity, versions, object counts, resources and update status (live Plesk state)')]
final class ServerInfoCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $info = $this->context()->gateway()->getServerInfo();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('Server:        %s', $info['server_name'] ?: '(unknown)'));
        $output->writeln(sprintf('Plesk:         %s (build %s)', $info['plesk_version'], $info['plesk_build']));
        $output->writeln(sprintf('OS:            %s %s', $info['plesk_os'], $info['os_release']));
        $output->writeln(sprintf('CPU:           %s', $info['cpu']));
        $output->writeln(sprintf('Uptime:        %s', $info['uptime']));
        $output->writeln(sprintf('Load average:  %s / %s / %s', $info['load_avg']['l1'], $info['load_avg']['l5'], $info['load_avg']['l15']));

        $output->writeln('Objects:');
        foreach ($info['objects'] as $name => $count) {
            if ('' !== (string) $count) {
                $output->writeln(sprintf('  %-16s %s', str_replace('_', ' ', $name), $count));
            }
        }

        $output->writeln('Updates:');
        foreach ($info['updates'] as $name => $value) {
            if ('' !== (string) $value) {
                $output->writeln(sprintf('  %-24s %s', str_replace('_', ' ', $name), $value));
            }
        }

        return self::SUCCESS;
    }
}
