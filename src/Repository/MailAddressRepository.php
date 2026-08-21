<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;

/**
 * Local-state helpers for mail addresses. The live server is the source of
 * truth; this only keeps the audit tables coherent when an address identity
 * changes (e.g. mail:address:rename).
 */
final class MailAddressRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $server,
    ) {}

    /**
     * Move every local record of an address to its new name. Only rows that
     * key on the mailbox address itself are touched; recipient memberships in
     * other groups are left alone (they reference addresses, not mailboxes).
     */
    public function renameLocal(string $oldEmail, string $newEmail): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE auto_replies SET email = :new_email WHERE server = :server AND email = :old_email',
        );
        $statement->execute(['new_email' => $newEmail, 'server' => $this->server, 'old_email' => $oldEmail]);

        $statement = $this->connection->pdo()->prepare(
            'UPDATE mail_aliases SET email = :new_email WHERE server = :server AND email = :old_email',
        );
        $statement->execute(['new_email' => $newEmail, 'server' => $this->server, 'old_email' => $oldEmail]);

        $statement = $this->connection->pdo()->prepare(
            'UPDATE mail_recipients SET list_email = :new_email WHERE server = :server AND list_email = :old_email',
        );
        $statement->execute(['new_email' => $newEmail, 'server' => $this->server, 'old_email' => $oldEmail]);
    }
}
