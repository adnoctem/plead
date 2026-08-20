<?php

declare(strict_types=1);

namespace App\Command\Server\Components;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:components:list', description: 'List installed Plesk components (live Plesk state)')]
final class ComponentsListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $components = $this->context()->gateway()->listComponents();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $components) {
            $output->writeln('No components reported by the server.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-32s %s', 'Component', 'Version'));
        foreach ($components as $component) {
            $output->writeln(sprintf(
                '%-32s %s',
                $component['name'],
                $component['version'],
            ));
        }

        return self::SUCCESS;
    }
}
