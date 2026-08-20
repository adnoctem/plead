<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Connection;
use App\Util\DateNormalizer;

final class SyncLogRepository
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Record an intent BEFORE the mutation reaches Plesk. The returned id is
     * passed to resolve() once the outcome is known, so the audit trail
     * always captures what was attempted - even if the RPC never happened.
     *
     * @param null|array<string, mixed> $details values involved in the change
     *                                           (e.g. rename from/to)
     */
    public function logPending(string $resourceType, string $resourceId, string $action, ?array $details = null): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
                INSERT INTO sync_log (resource_type, resource_id, action, result, details, occurred_at)
                VALUES (:resource_type, :resource_id, :action, 'pending', :details, :occurred_at)
                SQL,
        );
        $statement->execute([
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'details' => null === $details ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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

    /**
     * One-shot record for operations without a follow-up RPC (e.g. adoption).
     *
     * @param null|array<string, mixed> $details values involved in the change
     */
    public function log(string $resourceType, string $resourceId, string $action, string $result, ?array $details = null): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
                INSERT INTO sync_log (resource_type, resource_id, action, result, details, occurred_at)
                VALUES (:resource_type, :resource_id, :action, :result, :details, :occurred_at)
                SQL,
        );
        $statement->execute([
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'result' => $result,
            'details' => null === $details ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'occurred_at' => DateNormalizer::now(),
        ]);
    }

    /** @return array<int, array<string, string>> */
    public function recent(int $limit = 50): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, resource_type, resource_id, action, result, details, occurred_at FROM sync_log ORDER BY id DESC LIMIT :limit',
        );
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Newest-first audit entries, optionally filtered by resource type and
     * result ('pending'|'ok'|'dry-run'|'error' prefix match).
     *
     * @return array<int, array<string, string>>
     */
    public function all(?string $resourceType = null, ?string $result = null, ?int $limit = null): array
    {
        $sql = 'SELECT id, resource_type, resource_id, action, result, details, occurred_at FROM sync_log';
        $where = [];
        $params = [];
        if (null !== $resourceType) {
            $where[] = 'resource_type = :resource_type';
            $params['resource_type'] = $resourceType;
        }
        if (null !== $result) {
            $where[] = 'result = :result OR result LIKE :result_prefix';
            $params['result'] = $result;
            $params['result_prefix'] = $result.':%';
        }
        if ([] !== $where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC';
        if (null !== $limit) {
            $sql .= ' LIMIT :limit';
        }

        $statement = $this->connection->pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(':'.$name, $value);
        }
        if (null !== $limit) {
            $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
