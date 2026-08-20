<?php

declare(strict_types=1);

namespace App\Database;

final class Connection
{
    private \PDO $pdo;

    public function __construct(private readonly string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create data directory: %s', $directory));
        }

        $this->pdo = new \PDO('sqlite:' . $databasePath);
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
        $schemaFile = dirname(__DIR__, 2) . '/config/schema.sql';
        if (is_file($schemaFile)) {
            $this->pdo->exec(file_get_contents($schemaFile) ?: '');
        }

        // schema.sql only creates missing tables; databases created before the
        // status/reconciled model existed need their new columns added in place.
        $this->ensureColumns('auto_replies', [
            "status TEXT NOT NULL DEFAULT 'scheduled'",
            'reconciled INTEGER NOT NULL DEFAULT 0',
            'reconciled_at TEXT',
        ]);
        $this->ensureColumns('mail_recipients', [
            'reconciled INTEGER NOT NULL DEFAULT 0',
            'reconciled_at TEXT',
        ], static function (\PDO $pdo): void {
            // Rows created before the reconciled flag existed are considered
            // already reconciled until they change.
            $pdo->exec('UPDATE mail_recipients SET reconciled = 1');
        });

        // Legacy databases tracked the applied state in applied_at; fold it
        // into reconciled/reconciled_at and drop the column (guarded so the
        // migration is idempotent).
        if (in_array('applied_at', $this->columnNames('auto_replies'), true)) {
            $this->pdo->exec(
                'UPDATE auto_replies SET reconciled = CASE WHEN applied_at IS NOT NULL THEN 1 ELSE 0 END, reconciled_at = applied_at',
            );
            $this->pdo->exec('ALTER TABLE auto_replies DROP COLUMN applied_at');
        }
    }

    /** @param string[] $definitions full "name TYPE ..." column definitions */
    private function ensureColumns(string $table, array $definitions, ?callable $afterAdd = null): void
    {
        $existing = $this->columnNames($table);
        $added = false;
        foreach ($definitions as $definition) {
            $name = explode(' ', $definition, 2)[0];
            if (in_array($name, $existing, true)) {
                continue;
            }

            $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s', $table, $definition));
            $added = true;
        }

        if ($added && null !== $afterAdd) {
            $afterAdd($this->pdo);
        }
    }

    /** @return string[] */
    private function columnNames(string $table): array
    {
        $rows = $this->pdo->query(sprintf('PRAGMA table_info(%s)', $table))->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }
}
