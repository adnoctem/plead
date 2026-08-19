<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:path', description: 'Show where plead stores configuration and data')]
final class ConfigPathCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $this->context()->paths;

        foreach ($paths->configPaths() as $candidate) {
            $exists = is_file($candidate) ? ' (exists)' : '';
            $output->writeln('config: ' . $candidate . $exists);
        }
        $output->writeln('data dir: ' . $paths->dataDir());
        $output->writeln('cache dir: ' . $paths->cacheHome());
        $output->writeln('log file: ' . $paths->logFile());

        return self::SUCCESS;
    }
}
