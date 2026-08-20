<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class AutoReplyRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function upsert(string $email, string $message, string $startDate, string $endDate): void
    {
        // (Re)scheduling records a fresh intent: the previous reconcile state
        // no longer matches, so the row is flagged dirty for the watcher.
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
                INSERT INTO auto_replies (email, message, start_date, end_date, status, reconciled, reconciled_at, updated_at)
                VALUES (:email, :message, :start_date, :end_date, 'scheduled', 0, NULL, :updated_at)
                ON CONFLICT(email) DO UPDATE SET
                    message = :message,
                    start_date = :start_date,
                    end_date = :end_date,
                    status = 'scheduled',
                    reconciled = 0,
                    reconciled_at = NULL,
                    updated_at = :updated_at
                SQL,
        );
        $statement->execute([
            'email' => $email,
            'message' => $message,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'updated_at' => DateNormalizer::now(),
        ]);
    }

    /**
     * Mark an entry disabled. The row is deliberately kept - the status
     * column and the sync log form the audit trail - but reconciled is reset
     * so the watcher still has to push the disable to Plesk.
     */
    public function disable(string $email): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
                UPDATE auto_replies
                SET status = 'disabled',
                    reconciled = 0,
                    reconciled_at = NULL,
                    updated_at = :updated_at
                WHERE email = :email
                SQL,
        );
        $statement->execute([
            'email' => $email,
            'updated_at' => DateNormalizer::now(),
        ]);
    }

    /** @return null|array<string, mixed> */
    public function find(string $email): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT email, message, start_date, end_date, status, reconciled, reconciled_at, updated_at
             FROM auto_replies WHERE email = :email',
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return false === $row ? null : $row;
    }

    /**
     * Intents Plesk has not seen yet: scheduled replies whose start time has
     * arrived, plus entries waiting to be disabled.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->pdo()
            ->query('SELECT email, message, start_date, end_date, status, reconciled, reconciled_at, updated_at FROM auto_replies WHERE reconciled = 0')
            ->fetchAll(\PDO::FETCH_ASSOC)
        ;

        return $this->dueRows($rows, $now);
    }

    /**
     * Every entry that applies at the given time, reconciled or not - used by
     * the watcher's --full sweep to re-verify the server state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dueAll(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->pdo()
            ->query('SELECT email, message, start_date, end_date, status, reconciled, reconciled_at, updated_at FROM auto_replies')
            ->fetchAll(\PDO::FETCH_ASSOC)
        ;

        return $this->dueRows($rows, $now);
    }

    public function markReconciled(string $email, string $reconciledAt): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE auto_replies SET reconciled = 1, reconciled_at = :reconciled_at WHERE email = :email',
        );
        $statement->execute(['reconciled_at' => $reconciledAt, 'email' => $email]);
    }

    /** @return array<int, array<string, mixed>> */
    public function allEntries(): array
    {
        return $this->connection->pdo()
            ->query('SELECT email, message, start_date, end_date, status, reconciled, reconciled_at, updated_at FROM auto_replies ORDER BY email')
            ->fetchAll(\PDO::FETCH_ASSOC)
        ;
    }

    /**
     * A disabled entry applies whenever the watcher runs; a scheduled one only
     * once its start time has been reached (start_date is ISO 8601 with an
     * offset, so the comparison is done in PHP, not SQL).
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function dueRows(array $rows, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => 'disabled' === $row['status']
                || new \DateTimeImmutable($row['start_date']) <= $now,
        ));
    }
}
