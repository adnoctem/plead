<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class AutoReplyRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function upsert(string $email, string $message, string $startDate, string $endDate): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO auto_replies (email, message, start_date, end_date, applied_at, updated_at)
            VALUES (:email, :message, :start_date, :end_date, NULL, :updated_at)
            ON CONFLICT(email) DO UPDATE SET
                message = :message,
                start_date = :start_date,
                end_date = :end_date,
                applied_at = NULL,
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

    /** @return array<string, mixed>|null */
    public function find(string $email): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT email, message, start_date, end_date, applied_at, updated_at FROM auto_replies WHERE email = :email',
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return false === $row ? null : $row;
    }

    /**
     * Entries that have not yet been applied to Plesk and whose start time
     * has been reached.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due(\DateTimeImmutable $now): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT email, message, start_date, end_date, applied_at, updated_at FROM auto_replies WHERE applied_at IS NULL',
        );
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => new \DateTimeImmutable($row['start_date']) <= $now,
        ));
    }

    public function markApplied(string $email, string $appliedAt): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE auto_replies SET applied_at = :applied_at WHERE email = :email',
        );
        $statement->execute(['applied_at' => $appliedAt, 'email' => $email]);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->connection->pdo()
            ->query('SELECT email, message, start_date, end_date, applied_at, updated_at FROM auto_replies ORDER BY email')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }
}
