<?php

declare(strict_types=1);

namespace App\Database;

final class Connection
{
    private \PDO $pdo;

    public function __construct(private readonly string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create data directory: %s', $directory));
        }

        $this->pdo = new \PDO('sqlite:'.$databasePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA busy_timeout=5000;');
        $this->migrate();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->databasePath;
    }

    private function migrate(): void
    {
        // Pre-production: schema.sql is the source of truth. Databases from
        // older layouts are discarded, not migrated.
        $schemaFile = dirname(__DIR__, 2).'/config/schema.sql';
        if (is_file($schemaFile)) {
            $this->pdo->exec(file_get_contents($schemaFile) ?: '');
        }
    }
}
