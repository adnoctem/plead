<?php

declare(strict_types=1);

namespace App\Tests\Reconciler;

use App\Database\Connection;
use App\Reconciler\MailGroupReconciler;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use App\Tests\Support\RecordingGateway;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class MailGroupReconcilerTest extends TestCase
{
    private Connection $connection;
    private MailGroupRepository $repository;
    private SyncLogRepository $syncLog;
    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir().'/plead-mail-reconciler-'.bin2hex(random_bytes(4)).'/plead.sqlite');
        $this->repository = new MailGroupRepository($this->connection, 'fake.local');
        $this->syncLog = new SyncLogRepository($this->connection, 'fake.local');
        $this->gateway = new RecordingGateway();
    }

    public function testAddsMissingRecipientsAndMarksListReconciled(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');

        $changed = $this->reconciler()->reconcile('group@company.com');

        self::assertTrue($changed);
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['group@company.com']);
        self::assertSame([], $this->repository->unreconciledLists());
    }

    public function testRemovesUndeclaredRecipientsAndRecordsHistory(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->gateway->forwarding['group@company.com'] = ['alice@company.com', 'leaver@company.com'];

        $changed = $this->reconciler()->reconcile('group@company.com');

        self::assertTrue($changed);
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['group@company.com']);

        $removed = array_filter(
            $this->repository->history('group@company.com'),
            static fn (array $row): bool => null !== $row['removed_at'],
        );
        self::assertCount(1, $removed);
        self::assertSame('leaver@company.com', $removed[array_key_first($removed)]['recipient_email']);
    }

    public function testNoChangesWhenInSyncClearsPendingFlag(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->gateway->forwarding['group@company.com'] = ['alice@company.com'];

        $changed = $this->reconciler()->reconcile('group@company.com');

        self::assertFalse($changed);
        self::assertSame([], $this->repository->unreconciledLists());
        self::assertSame([], $this->syncLog->recent());
    }

    public function testReconcileAllCoversOnlyDirtyLists(): void
    {
        $this->repository->upsertActive('group@company.com', 'a@company.com');
        $this->repository->upsertActive('all@company.com', 'b@company.com');
        $this->repository->markListReconciled('all@company.com');

        $changed = $this->reconciler()->reconcileAll();

        self::assertSame(1, $changed);
        self::assertSame(['a@company.com'], $this->gateway->forwarding['group@company.com']);
        self::assertArrayNotHasKey('all@company.com', $this->gateway->forwarding);
    }

    public function testFullSweepCoversEveryManagedList(): void
    {
        $this->repository->upsertActive('group@company.com', 'a@company.com');
        $this->repository->upsertActive('all@company.com', 'b@company.com');
        $this->repository->markListReconciled('all@company.com');

        $changed = $this->reconciler()->reconcileAll(true);

        self::assertSame(2, $changed);
        self::assertSame(['a@company.com'], $this->gateway->forwarding['group@company.com']);
        self::assertSame(['b@company.com'], $this->gateway->forwarding['all@company.com']);
    }

    public function testFailureKeepsListDirtyForRetry(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->repository->upsertActive('all@company.com', 'bob@company.com');
        $this->gateway->failFor = ['group@company.com'];

        $changed = $this->reconciler()->reconcileAll();

        self::assertSame(1, $changed);
        self::assertSame(['group@company.com'], $this->repository->unreconciledLists());
        self::assertSame(['bob@company.com'], $this->gateway->forwarding['all@company.com']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('error:boom', $results);
        self::assertContains('ok', $results);
    }

    public function testRetryAfterFailureCompletesTheDiff(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->gateway->failFor = ['group@company.com'];

        $reconciler = $this->reconciler();
        self::assertFalse($reconciler->reconcile('group@company.com'));
        self::assertSame(['group@company.com'], $this->repository->unreconciledLists());

        $this->gateway->failFor = [];
        self::assertTrue($reconciler->reconcile('group@company.com'));
        self::assertSame(['alice@company.com'], $this->gateway->forwarding['group@company.com']);
        self::assertSame([], $this->repository->unreconciledLists());
    }

    public function testAdoptSeedsRepositoryFromPleskOnlyOnFirstTouch(): void
    {
        $this->gateway->forwarding['group@company.com'] = ['admin@company.com', 'boss@company.com'];

        self::assertTrue($this->reconciler()->adopt('group@company.com'));
        self::assertFalse($this->reconciler()->adopt('group@company.com'));

        self::assertSame(
            ['admin@company.com', 'boss@company.com'],
            $this->repository->activeRecipients('group@company.com'),
        );
    }

    public function testAddAfterAdoptKeepsPreExistingRecipients(): void
    {
        $this->gateway->forwarding['group@company.com'] = ['admin@company.com'];

        $this->reconciler()->adopt('group@company.com');
        $this->repository->upsertActive('group@company.com', 'newhire@company.com');
        $this->reconciler()->reconcile('group@company.com');

        self::assertSame(
            ['admin@company.com', 'newhire@company.com'],
            $this->gateway->forwarding['group@company.com'],
        );
    }

    public function testDryRunComputesDiffWithoutMutating(): void
    {
        $gateway = new RecordingGateway(dryRunMode: true);
        $reconciler = new MailGroupReconciler($this->repository, $this->syncLog, $gateway, new Logger('test'), true);

        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $gateway->forwarding['group@company.com'] = ['leaver@company.com'];

        $changed = $reconciler->reconcile('group@company.com');

        self::assertTrue($changed);
        self::assertSame(['leaver@company.com'], $gateway->forwarding['group@company.com']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('dry-run', $results);
        self::assertSame(['alice@company.com'], $this->repository->activeRecipients('group@company.com'));
        self::assertSame(['group@company.com'], $this->repository->unreconciledLists());
    }

    private function reconciler(bool $dryRun = false): MailGroupReconciler
    {
        return new MailGroupReconciler($this->repository, $this->syncLog, $this->gateway, new Logger('test'), $dryRun);
    }
}
