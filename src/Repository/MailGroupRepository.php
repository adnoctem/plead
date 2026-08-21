<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class MailGroupRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $server,
    ) {}

    /** Add or re-activate a recipient for a list. */
    public function upsertActive(string $listEmail, string $recipientEmail): void
    {
        // Every local change records a fresh intent: the row is flagged dirty
        // until the watcher confirms it on Plesk.
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
                INSERT INTO mail_recipients (server, list_email, recipient_email, removed_at, reconciled, reconciled_at, updated_at)
                VALUES (:server, :list_email, :recipient_email, NULL, 0, NULL, :updated_at)
                ON CONFLICT(server, list_email, recipient_email) DO UPDATE SET
                    removed_at = NULL,
                    reconciled = 0,
                    reconciled_at = NULL,
                    updated_at = :updated_at
                SQL,
        );
        $statement->execute([
            'server' => $this->server,
            'list_email' => $listEmail,
            'recipient_email' => $recipientEmail,
            'updated_at' => DateNormalizer::now(),
        ]);
    }

    /** Soft-delete a recipient for a list, preserving history. */
    public function remove(string $listEmail, string $recipientEmail): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
                INSERT INTO mail_recipients (server, list_email, recipient_email, removed_at, reconciled, reconciled_at, updated_at)
                VALUES (:server, :list_email, :recipient_email, :removed_at, 0, NULL, :updated_at)
                ON CONFLICT(server, list_email, recipient_email) DO UPDATE SET
                    removed_at = COALESCE(removed_at, :removed_at),
                    reconciled = 0,
                    reconciled_at = NULL,
                    updated_at = :updated_at
                SQL,
        );
        $statement->execute([
            'server' => $this->server,
            'list_email' => $listEmail,
            'recipient_email' => $recipientEmail,
            'removed_at' => DateNormalizer::now(),
            'updated_at' => DateNormalizer::now(),
        ]);
    }

    /** @return string[] currently active recipient emails for a list */
    public function activeRecipients(string $listEmail): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT recipient_email FROM mail_recipients
             WHERE server = :server AND list_email = :list_email AND removed_at IS NULL
             ORDER BY recipient_email',
        );
        $statement->execute(['server' => $this->server, 'list_email' => $listEmail]);

        return array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'recipient_email');
    }

    /**
     * Lists with at least one recipient awaiting confirmation on Plesk - the
     * watcher's work queue.
     *
     * @return string[]
     */
    public function unreconciledLists(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT DISTINCT list_email FROM mail_recipients
             WHERE server = :server AND reconciled = 0
             ORDER BY list_email',
        );
        $statement->execute(['server' => $this->server]);

        return array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'list_email');
    }

    /**
     * Mark every recipient of a list as confirmed on Plesk. Only called after
     * the whole diff has been applied, so a partially failed pass stays dirty
     * and the watcher retries (the RPCs are idempotent).
     */
    public function markListReconciled(string $listEmail): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE mail_recipients
             SET reconciled = 1, reconciled_at = :reconciled_at
             WHERE server = :server AND list_email = :list_email',
        );
        $statement->execute([
            'reconciled_at' => DateNormalizer::now(),
            'server' => $this->server,
            'list_email' => $listEmail,
        ]);
    }

    /** @return string[] email addresses of every list plead manages */
    public function managedLists(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT DISTINCT list_email FROM mail_recipients
             WHERE server = :server
             ORDER BY list_email',
        );
        $statement->execute(['server' => $this->server]);

        return array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'list_email');
    }

    public function hasHistory(string $listEmail): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM mail_recipients WHERE server = :server AND list_email = :list_email',
        );
        $statement->execute(['server' => $this->server, 'list_email' => $listEmail]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array<int, array{recipient_email: string, removed_at: null|string, reconciled: string, updated_at: string}> full row history for a list */
    public function history(string $listEmail): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT recipient_email, removed_at, reconciled, updated_at
             FROM mail_recipients
             WHERE server = :server AND list_email = :list_email
             ORDER BY removed_at IS NOT NULL, recipient_email',
        );
        $statement->execute(['server' => $this->server, 'list_email' => $listEmail]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * One row per managed list with activity counts for mail:group:list
     * (--local).
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT list_email,
                    SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN removed_at IS NOT NULL THEN 1 ELSE 0 END) AS removed_count,
                    SUM(CASE WHEN reconciled = 0 THEN 1 ELSE 0 END) AS pending_count
             FROM mail_recipients
             WHERE server = :server
             GROUP BY list_email
             ORDER BY list_email',
        );
        $statement->execute(['server' => $this->server]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Every distinct recipient address with its memberships for
     * mail:address:list (--local).
     *
     * @return array<int, array{recipient_email: string, list_email: string, removed_at: null|string, reconciled: string, updated_at: string}>
     */
    public function addressIndex(): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT recipient_email, list_email, removed_at, reconciled, updated_at
             FROM mail_recipients
             WHERE server = :server
             ORDER BY recipient_email, list_email',
        );
        $statement->execute(['server' => $this->server]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{list_email: string, removed_at: null|string, reconciled: string}> memberships of one address */
    public function listsOf(string $recipientEmail): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT list_email, removed_at, reconciled FROM mail_recipients
             WHERE server = :server AND recipient_email = :recipient_email
             ORDER BY list_email',
        );
        $statement->execute(['server' => $this->server, 'recipient_email' => $recipientEmail]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
