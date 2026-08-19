<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Database\Connection;
use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use PHPUnit\Framework\TestCase;

final class AutoReplyRepositoryTest extends TestCase
{
    private Connection $connection;
    private AutoReplyRepository $repository;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir() . '/plead-repo-test-' . bin2hex(random_bytes(4)) . '/plead.sqlite');
        $this->repository = new AutoReplyRepository($this->connection);
    }

    public function testUpsertInsertsAndResetsAppliedAt(): void
    {
        $this->repository->upsert('user@company.com', 'Message', '2026-08-19T08:00:00+02:00', '2026-08-20T08:00:00+02:00');
        $this->repository->markApplied('user@company.com', '2026-08-19T08:00:00+02:00');

        $row = $this->repository->find('user@company.com');
        self::assertNotNull($row['applied_at']);

        $this->repository->upsert('user@company.com', 'New message', '2026-08-21T08:00:00+02:00', '2026-08-22T08:00:00+02:00');

        $row = $this->repository->find('user@company.com');
        self::assertSame('New message', $row['message']);
        self::assertNull($row['applied_at']);
        self::assertSame('2026-08-21T08:00:00+02:00', $row['start_date']);
    }

    public function testDueReturnsOnlyReachedAndUnappliedEntries(): void
    {
        $now = new \DateTimeImmutable('2026-08-19T12:00:00+02:00');

        $this->repository->upsert('future@company.com', 'm1', '2026-08-20T08:00:00+02:00', '2026-08-21T08:00:00+02:00');
        $this->repository->upsert('due@company.com', 'm2', '2026-08-19T08:00:00+02:00', '2026-08-20T08:00:00+02:00');
        $this->repository->upsert('applied@company.com', 'm3', '2026-08-18T08:00:00+02:00', '2026-08-19T08:00:00+02:00');
        $this->repository->markApplied('applied@company.com', '2026-08-18T08:00:01+02:00');

        $emails = array_column($this->repository->due($now), 'email');

        self::assertSame(['due@company.com'], $emails);
    }

    public function testDueComparesInstantsNotLexicographically(): void
    {
        $now = new \DateTimeImmutable('2026-08-19T12:00:00+02:00');

        $this->repository->upsert('utc@company.com', 'm', '2026-08-19T09:30:00+00:00', '2026-08-20T09:00:00+00:00');

        $emails = array_column($this->repository->due($now), 'email');

        self::assertSame(['utc@company.com'], $emails);
    }

    public function testSyncLogRecordsEntries(): void
    {
        $syncLog = new SyncLogRepository($this->connection);
        $syncLog->log('auto_reply', 'user@company.com', 'apply', 'ok');
        $syncLog->log('auto_reply', 'user@company.com', 'apply', 'error:boom');

        $entries = $syncLog->recent();

        self::assertCount(2, $entries);
        self::assertSame('auto_reply', $entries[1]['resource_type']);
        self::assertSame('error:boom', $entries[0]['result']);
    }
}
