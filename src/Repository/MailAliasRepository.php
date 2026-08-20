<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class MailAliasRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** Add or re-activate an alias for a mailbox. */
    public function upsertActive(string $email, string $aliasEmail): void
    {
        // Every local change records a fresh intent: the row is flagged dirty
        // until the reconciler confirms it on Plesk.
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO mail_aliases (email, alias_email, removed_at, reconciled, reconciled_at, updated_at)
            VALUES (:email, :alias_email, NULL, 0, NULL, :updated_at)
            ON CONFLICT(email, alias_email) DO UPDATE SET
                removed_at = NULL,
                reconciled = 0,
                reconciled_at = NULL,
                updated_at = :updated_at
            SQL,
        );
        $statement->execute([
            'email' => $email,
            'alias_email' => $aliasEmail,
            'updated_at' => DateNormalizer::now(),
        ]);
    }

    /** Soft-delete an alias, preserving history. */
    public function remove(string $email, string $aliasEmail): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO mail_aliases (email, alias_email, removed_at, reconciled, reconciled_at, updated_at)
            VALUES (:email, :alias_email, :removed_at, 0, NULL, :updated_at)
            ON CONFLICT(email, alias_email) DO UPDATE SET
                removed_at = COALESCE(removed_at, :removed_at),
                reconciled = 0,
                reconciled_at = NULL,
                updated_at = :updated_at
            SQL,
        );
        $statement->execute([
            'email' => $email,
            'alias_email' => $aliasEmail,
            'removed_at' => DateNormalizer::now(),
            'updated_at' => DateNormalizer::now(),
        ]);
    }

    /** @return string[] currently active alias addresses for a mailbox */
    public function activeAliases(string $email): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT alias_email FROM mail_aliases WHERE email = :email AND removed_at IS NULL ORDER BY alias_email',
        );
        $statement->execute(['email' => $email]);

        return array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'alias_email');
    }

    /**
     * Mailboxes with at least one alias awaiting confirmation on Plesk.
     *
     * @return string[]
     */
    public function unreconciledLists(): array
    {
        return array_column(
            $this->connection->pdo()
                ->query('SELECT DISTINCT email FROM mail_aliases WHERE reconciled = 0 ORDER BY email')
                ->fetchAll(\PDO::FETCH_ASSOC),
            'email',
        );
    }

    /**
     * Mark every alias of a mailbox as confirmed on Plesk. Only called after
     * the whole diff has been applied, so a partially failed pass stays dirty
     * and the reconciler retries (the RPCs are idempotent).
     */
    public function markListReconciled(string $email): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE mail_aliases SET reconciled = 1, reconciled_at = :reconciled_at WHERE email = :email',
        );
        $statement->execute(['reconciled_at' => DateNormalizer::now(), 'email' => $email]);
    }

    /** @return string[] email addresses of every mailbox plead manages aliases for */
    public function managedLists(): array
    {
        return array_column(
            $this->connection->pdo()
                ->query('SELECT DISTINCT email FROM mail_aliases ORDER BY email')
                ->fetchAll(\PDO::FETCH_ASSOC),
            'email',
        );
    }

    public function hasHistory(string $email): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM mail_aliases WHERE email = :email',
        );
        $statement->execute(['email' => $email]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array<int, array<string, string>> full row history for a mailbox */
    public function history(string $email): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT alias_email, removed_at, reconciled, updated_at
             FROM mail_aliases
             WHERE email = :email
             ORDER BY removed_at IS NOT NULL, alias_email',
        );
        $statement->execute(['email' => $email]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * One row per mailbox with activity counts for mail:alias:list (--local).
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(): array
    {
        return $this->connection->pdo()
            ->query(
                'SELECT email,
                        SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) AS active_count,
                        SUM(CASE WHEN removed_at IS NOT NULL THEN 1 ELSE 0 END) AS removed_count,
                        SUM(CASE WHEN reconciled = 0 THEN 1 ELSE 0 END) AS pending_count
                 FROM mail_aliases
                 GROUP BY email
                 ORDER BY email',
            )
            ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
