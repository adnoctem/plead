<?php

declare(strict_types=1);

namespace App\Command\Db;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:path', description: 'Show the location of the SQLite database')]
final class DbPathCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->context()->databaseFile();
        $exists = is_file($path) ? ' (exists)' : '';
        $output->writeln('database: ' . $path . $exists);

        return self::SUCCESS;
    }
}
