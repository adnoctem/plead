<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'config:get', description: 'Show the resolved value of a configuration key')]
final class ConfigGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Dotted configuration key, e.g. plesk.host');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->context()->config();
        $value = self::nestedGet($config, $input->getArgument('key'));

        if (null === $value) {
            $output->writeln(sprintf('<error>Unknown configuration key: %s</error>', $input->getArgument('key')));

            return self::FAILURE;
        }

        if (is_array($value)) {
            $output->writeln(Yaml::dump($value));

            return self::SUCCESS;
        }

        $output->writeln((string) $value);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $config */
    private static function nestedGet(array $config, string $key): mixed
    {
        $current = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
