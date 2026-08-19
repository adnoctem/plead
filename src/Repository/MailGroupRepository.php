<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class MailGroupRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** Add or re-activate a recipient for a list. */
    public function upsertActive(string $listEmail, string $recipientEmail): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO mail_recipients (list_email, recipient_email, removed_at, updated_at)
            VALUES (:list_email, :recipient_email, NULL, :updated_at)
            ON CONFLICT(list_email, recipient_email) DO UPDATE SET
                removed_at = NULL,
                updated_at = :updated_at
            SQL,
        );
        $statement->execute([
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
            INSERT INTO mail_recipients (list_email, recipient_email, removed_at, updated_at)
            VALUES (:list_email, :recipient_email, :removed_at, :updated_at)
            ON CONFLICT(list_email, recipient_email) DO UPDATE SET
                removed_at = COALESCE(removed_at, :removed_at),
                updated_at = :updated_at
            SQL,
        );
        $statement->execute([
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
            'SELECT recipient_email FROM mail_recipients WHERE list_email = :list_email AND removed_at IS NULL ORDER BY recipient_email',
        );
        $statement->execute(['list_email' => $listEmail]);

        return array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'recipient_email');
    }

    /** @return string[] email addresses of every list plead manages */
    public function managedLists(): array
    {
        return array_column(
            $this->connection->pdo()
                ->query('SELECT DISTINCT list_email FROM mail_recipients ORDER BY list_email')
                ->fetchAll(\PDO::FETCH_ASSOC),
            'list_email',
        );
    }

    public function hasHistory(string $listEmail): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM mail_recipients WHERE list_email = :list_email',
        );
        $statement->execute(['list_email' => $listEmail]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array<int, array<string, string>> full row history for a list */
    public function history(string $listEmail): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT recipient_email, removed_at, updated_at FROM mail_recipients WHERE list_email = :list_email ORDER BY removed_at IS NOT NULL, recipient_email',
        );
        $statement->execute(['list_email' => $listEmail]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
