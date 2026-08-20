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

    public function testAppliesDueEntriesAndMarksReconciled(): void
    {
        $this->repository->upsert('due@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');
        $this->repository->upsert('future@company.com', 'msg', '2099-01-01T08:00:00+02:00', '2099-01-02T08:00:00+02:00');

        $applied = $this->reconciler()->reconcileAll();

        self::assertSame(1, $applied);
        self::assertSame(['due@company.com'], $this->gateway->calls);
        self::assertSame('1', (string) $this->repository->find('due@company.com')['reconciled']);
        self::assertSame('0', (string) $this->repository->find('future@company.com')['reconciled']);
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
        self::assertSame('0', (string) $this->repository->find('bad@company.com')['reconciled']);
        self::assertSame('1', (string) $this->repository->find('good@company.com')['reconciled']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('error:boom', $results);
        self::assertContains('ok', $results);
    }

    public function testDryRunDoesNotMarkReconciled(): void
    {
        $this->repository->upsert('due@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');

        $applied = $this->reconciler(true)->reconcileAll();

        self::assertSame(0, $applied);
        self::assertSame('0', (string) $this->repository->find('due@company.com')['reconciled']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('dry-run', $results);
    }

    public function testDisabledEntriesArePushedAsDisable(): void
    {
        $this->repository->upsert('user@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');
        $this->repository->markReconciled('user@company.com', '2020-01-01T08:00:00+02:00');
        $this->repository->disable('user@company.com');

        $applied = $this->reconciler()->reconcileAll();

        self::assertSame(1, $applied);
        self::assertSame(['user@company.com'], $this->gateway->calls);
        self::assertFalse($this->gateway->autoresponders['user@company.com']);
        self::assertSame('1', (string) $this->repository->find('user@company.com')['reconciled']);
    }

    public function testFullSweepReappliesReconciledEntries(): void
    {
        $this->repository->upsert('user@company.com', 'msg', '2020-01-01T08:00:00+02:00', '2020-01-02T08:00:00+02:00');
        $this->repository->markReconciled('user@company.com', '2020-01-01T08:00:00+02:00');

        $applied = $this->reconciler()->reconcileAll(true);

        self::assertSame(1, $applied);
        self::assertCount(1, $this->gateway->calls);
    }
}
