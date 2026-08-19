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
