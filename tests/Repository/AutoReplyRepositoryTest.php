<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Database\Connection;
use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class AutoReplyRepositoryTest extends TestCase
{
    private Connection $connection;
    private AutoReplyRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir().'/plead-repo-test-'.bin2hex(random_bytes(4)).'/plead.sqlite');
        $this->repository = new AutoReplyRepository($this->connection);
    }

    public function testUpsertInsertsAndResetsReconciledState(): void
    {
        $this->repository->upsert('user@company.com', 'Message', '2026-08-19T08:00:00+02:00', '2026-08-20T08:00:00+02:00');
        $this->repository->markReconciled('user@company.com', '2026-08-19T08:00:00+02:00');

        $row = $this->repository->find('user@company.com');
        self::assertSame('1', (string) $row['reconciled']);
        self::assertSame('scheduled', $row['status']);

        $this->repository->upsert('user@company.com', 'New message', '2026-08-21T08:00:00+02:00', '2026-08-22T08:00:00+02:00');

        $row = $this->repository->find('user@company.com');
        self::assertSame('New message', $row['message']);
        self::assertSame('0', (string) $row['reconciled']);
        self::assertNull($row['reconciled_at']);
        self::assertSame('scheduled', $row['status']);
        self::assertSame('2026-08-21T08:00:00+02:00', $row['start_date']);
    }

    public function testDisableKeepsRowAndFlagsDirty(): void
    {
        $this->repository->upsert('user@company.com', 'Message', '2026-08-19T08:00:00+02:00', '2026-08-20T08:00:00+02:00');
        $this->repository->markReconciled('user@company.com', '2026-08-19T08:00:00+02:00');

        $this->repository->disable('user@company.com');

        $row = $this->repository->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame('0', (string) $row['reconciled']);
        self::assertNull($row['reconciled_at']);
    }

    public function testPendingReturnsOnlyReachedAndUnreconciledEntries(): void
    {
        $now = new \DateTimeImmutable('2026-08-19T12:00:00+02:00');

        $this->repository->upsert('future@company.com', 'm1', '2026-08-20T08:00:00+02:00', '2026-08-21T08:00:00+02:00');
        $this->repository->upsert('due@company.com', 'm2', '2026-08-19T08:00:00+02:00', '2026-08-20T08:00:00+02:00');
        $this->repository->upsert('reconciled@company.com', 'm3', '2026-08-18T08:00:00+02:00', '2026-08-19T08:00:00+02:00');
        $this->repository->markReconciled('reconciled@company.com', '2026-08-18T08:00:01+02:00');
        $this->repository->upsert('disabled@company.com', 'm4', '2026-08-18T08:00:00+02:00', '2026-08-19T08:00:00+02:00');
        $this->repository->disable('disabled@company.com');

        $emails = array_column($this->repository->pending($now), 'email');

        self::assertSame(['due@company.com', 'disabled@company.com'], $emails);
    }

    public function testPendingComparesInstantsNotLexicographically(): void
    {
        $now = new \DateTimeImmutable('2026-08-19T12:00:00+02:00');

        $this->repository->upsert('utc@company.com', 'm', '2026-08-19T09:30:00+00:00', '2026-08-20T09:00:00+00:00');

        $emails = array_column($this->repository->pending($now), 'email');

        self::assertSame(['utc@company.com'], $emails);
    }

    public function testDueAllIncludesReconciledEntriesForFullSweep(): void
    {
        $now = new \DateTimeImmutable('2026-08-19T12:00:00+02:00');

        $this->repository->upsert('due@company.com', 'm1', '2026-08-19T08:00:00+02:00', '2026-08-20T08:00:00+02:00');
        $this->repository->upsert('reconciled@company.com', 'm2', '2026-08-18T08:00:00+02:00', '2026-08-19T08:00:00+02:00');
        $this->repository->markReconciled('reconciled@company.com', '2026-08-18T08:00:01+02:00');

        $emails = array_column($this->repository->dueAll($now), 'email');

        self::assertSame(['due@company.com', 'reconciled@company.com'], $emails);
    }

    public function testSyncLogPendingAndResolve(): void
    {
        $syncLog = new SyncLogRepository($this->connection);
        $id = $syncLog->logPending('auto_reply', 'user@company.com', 'apply');
        $syncLog->resolve($id, 'ok');
        $syncLog->logPending('auto_reply', 'user@company.com', 'apply');

        $entries = $syncLog->recent();

        self::assertCount(2, $entries);
        self::assertSame('pending', $entries[0]['result']);
        self::assertSame('ok', $entries[1]['result']);
        self::assertSame('auto_reply', $entries[1]['resource_type']);
    }
}
