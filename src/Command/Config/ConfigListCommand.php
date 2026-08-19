<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'config:list', description: 'Show the resolved configuration')]
final class ConfigListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->context()->config();
        } catch (InvalidConfigurationException $e) {
            $output->writeln('<error>No complete configuration found.</error>');
            $output->writeln('Set the required keys first, e.g.:');
            $output->writeln('  plead config:set plesk.host mail.company.com');
            $output->writeln('  plead config:set plesk.secret_key <secret-key>');

            return self::FAILURE;
        }

        if (!empty($config['plesk']['secret_key'])) {
            $config['plesk']['secret_key'] = '***';
        }
        if (!empty($config['plesk']['password'])) {
            $config['plesk']['password'] = '***';
        }

        $output->writeln(Yaml::dump($config, 8, 4));

        return self::SUCCESS;
    }
}
