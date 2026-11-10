<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use App\Config\ConfigPresenter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'config:list', description: 'Show the resolved configuration (credentials masked)')]
final class ConfigListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->context()->config();
        } catch (InvalidConfigurationException $e) {
            $output->writeln('<error>No complete configuration found.</error>');
            $output->writeln('Set the required keys first, e.g.:');
            $output->writeln('  plead config:set servers.0.host mail.company.com');
            $output->writeln('  plead config:set servers.0.secret_key <secret-key>');

            return self::FAILURE;
        }

        $output->writeln(Yaml::dump(ConfigPresenter::forOutput($config, true), 8, 4));

        return self::SUCCESS;
    }
}
