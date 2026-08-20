<?php

declare(strict_types=1);

namespace App\Command\Db;

use App\Command\AbstractPleadCommand;
use App\Util\InteractiveProcessLauncher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:query', description: 'Open an interactive SQLite shell on the database')]
final class DbQueryCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ensure the data directory, database file and schema exist so the
        // shell opens on a database the application can actually use.
        $this->context()->connection();

        $launcher = new InteractiveProcessLauncher();
        if (null === $launcher->resolve('sqlite3')) {
            $output->writeln('<error>sqlite3 was not found on your PATH. Install it (e.g. "apt install sqlite3") and try again.</error>');

            return self::FAILURE;
        }

        $path = $this->context()->databaseFile();
        $output->writeln(sprintf('Opening %s with sqlite3', $path));

        $exitCode = $launcher->run(['sqlite3', $path], $output, 'sqlite3');
        if (0 !== $exitCode) {
            $output->writeln(sprintf('<error>sqlite3 exited with status %d.</error>', $exitCode));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
