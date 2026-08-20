<?php

declare(strict_types=1);

namespace App\Command\Server\Extension;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:extension:list', description: 'List installed extensions (live Plesk state)')]
final class ExtensionListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $extensions = $this->context()->gateway()->listExtensions();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $extensions) {
            $output->writeln('No extensions installed.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-24s %-30s %-12s %-8s %s', 'ID', 'Name', 'Version', 'Release', 'Active'));
        foreach ($extensions as $extension) {
            $output->writeln(sprintf(
                '%-24s %-30s %-12s %-8s %s',
                $extension['id'],
                $extension['name'],
                $extension['version'],
                $extension['release'],
                $extension['active'] ? 'yes' : 'no',
            ));
        }

        return self::SUCCESS;
    }
}
