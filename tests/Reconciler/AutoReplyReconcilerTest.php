<?php

declare(strict_types=1);

namespace App\Tests\Reconciler;

use App\Database\Connection;
use App\Reconciler\AutoReplyReconciler;
use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use App\Tests\Support\RecordingGateway;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class AutoReplyReconcilerTest extends TestCase
{
    private Connection $connection;
    private AutoReplyRepository $repository;
    private SyncLogRepository $syncLog;
    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir() . '/plead-reconciler-test-' . bin2hex(random_bytes(4)) . '/plead.sqlite');
        $this->repository = new AutoReplyRepository($this->connection);
        $this->syncLog = new SyncLogRepository($this->connection);
        $this->gateway = new RecordingGateway();
    }

    private function reconciler(bool $dryRun = false): AutoReplyReconciler
    {
        return new AutoReplyReconciler($this->repository, $this->syncLog, $this->gateway, new Logger('test'), $dryRun);
    }

    public function testAppliesDueEntriesAndMarksApplied(): void
    {
        $this->repository->upsert('due@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');
        $this->repository->upsert('future@company.com', 'msg', '2099-01-01T08:00:00+02:00', '2099-01-02T08:00:00+02:00');

        $applied = $this->reconciler()->reconcileAll();

        self::assertSame(1, $applied);
        self::assertSame(['due@company.com'], $this->gateway->calls);
        self::assertNotNull($this->repository->find('due@company.com')['applied_at']);
        self::assertNull($this->repository->find('future@company.com')['applied_at']);
    }

    public function testDoesNotReapplyAfterSuccess(): void
    {
        $this->repository->upsert('due@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');

        $this->reconciler()->reconcileAll();
        $this->reconciler()->reconcileAll();

        self::assertCount(1, $this->gateway->calls);
        $entries = array_column($this->syncLog->recent(), 'result');
        self::assertSame(['ok'], $entries);
    }

    public function testFailureIsIsolatedPerEntry(): void
    {
        $this->gateway->failFor = ['bad@company.com'];
        $this->repository->upsert('bad@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');
        $this->repository->upsert('good@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');

        $applied = $this->reconciler()->reconcileAll();

        self::assertSame(1, $applied);
        self::assertNull($this->repository->find('bad@company.com')['applied_at']);
        self::assertNotNull($this->repository->find('good@company.com')['applied_at']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('error:boom', $results);
        self::assertContains('ok', $results);
    }

    public function testDryRunDoesNotMarkApplied(): void
    {
        $this->repository->upsert('due@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');

        $applied = $this->reconciler(true)->reconcileAll();

        self::assertSame(0, $applied);
        self::assertNull($this->repository->find('due@company.com')['applied_at']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('dry-run', $results);
    }
}
