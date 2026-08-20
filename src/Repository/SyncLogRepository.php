<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class SyncLogRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Record an intent BEFORE the mutation reaches Plesk. The returned id is
     * passed to resolve() once the outcome is known, so the audit trail
     * always captures what was attempted - even if the RPC never happened.
     */
    public function logPending(string $resourceType, string $resourceId, string $action): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO sync_log (resource_type, resource_id, action, result, occurred_at)
            VALUES (:resource_type, :resource_id, :action, 'pending', :occurred_at)
            SQL,
        );
        $statement->execute([
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'occurred_at' => DateNormalizer::now(),
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /** Finalize a pending intent: 'ok' | 'dry-run' | 'error:<message>'. */
    public function resolve(int $logId, string $result): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE sync_log SET result = :result WHERE id = :id',
        );
        $statement->execute(['result' => $result, 'id' => $logId]);
    }

    /** One-shot record for operations without a follow-up RPC (e.g. adoption). */
    public function log(string $resourceType, string $resourceId, string $action, string $result): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO sync_log (resource_type, resource_id, action, result, occurred_at)
            VALUES (:resource_type, :resource_id, :action, :result, :occurred_at)
            SQL,
        );
        $statement->execute([
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'result' => $result,
            'occurred_at' => DateNormalizer::now(),
        ]);
    }

    /** @return array<int, array<string, string>> */
    public function recent(int $limit = 50): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, resource_type, resource_id, action, result, occurred_at FROM sync_log ORDER BY id DESC LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
