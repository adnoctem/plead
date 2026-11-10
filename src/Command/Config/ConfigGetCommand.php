<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use App\Config\SecretMasker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'config:get', description: 'Show the resolved value of a configuration key (secrets masked)')]
final class ConfigGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Dotted configuration key, e.g. servers.0.host')
            ->addOption('expose-secrets', null, InputOption::VALUE_NONE, 'Print secret_key/password values instead of masking them')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('key');
        $config = $this->context()->config();
        $value = self::nestedGet($config, $key);
        $expose = (bool) $input->getOption('expose-secrets');

        if (!$expose) {
            $lastSegment = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
            if (null !== $value && SecretMasker::isSecretKey($lastSegment)) {
                $value = '***';
            }
            $value = SecretMasker::mask($value);
        }

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
