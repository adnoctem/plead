<?php

declare(strict_types=1);

namespace App\Tests\Reconciler;

use App\Database\Connection;
use App\Reconciler\MailAliasReconciler;
use App\Repository\MailAliasRepository;
use App\Repository\SyncLogRepository;
use App\Tests\Support\RecordingGateway;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class MailAliasReconcilerTest extends TestCase
{
    private Connection $connection;
    private MailAliasRepository $repository;
    private SyncLogRepository $syncLog;
    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir().'/plead-alias-reconciler-'.bin2hex(random_bytes(4)).'/plead.sqlite');
        $this->repository = new MailAliasRepository($this->connection);
        $this->syncLog = new SyncLogRepository($this->connection);
        $this->gateway = new RecordingGateway();
    }

    public function testAddsMissingAliasesAndMarksMailboxReconciled(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');

        $changed = $this->reconciler()->reconcile('user@company.com');

        self::assertTrue($changed);
        self::assertSame(['info@company.com'], $this->gateway->aliases['user@company.com']);
        self::assertSame([], $this->repository->unreconciledLists());
    }

    public function testRemovesUndeclaredAliasesAndRecordsHistory(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->gateway->aliases['user@company.com'] = ['info@company.com', 'leaver@company.com'];

        $changed = $this->reconciler()->reconcile('user@company.com');

        self::assertTrue($changed);
        self::assertSame(['info@company.com'], $this->gateway->aliases['user@company.com']);

        $removed = array_filter(
            $this->repository->history('user@company.com'),
            static fn (array $row): bool => null !== $row['removed_at'],
        );
        self::assertCount(1, $removed);
        self::assertSame('leaver@company.com', $removed[array_key_first($removed)]['alias_email']);
    }

    public function testNoChangesWhenInSyncClearsPendingFlag(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->gateway->aliases['user@company.com'] = ['info@company.com'];

        $changed = $this->reconciler()->reconcile('user@company.com');

        self::assertFalse($changed);
        self::assertSame([], $this->repository->unreconciledLists());
        self::assertSame([], $this->syncLog->recent());
    }

    public function testFailureKeepsMailboxDirtyForRetry(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->gateway->failFor = ['user@company.com'];

        $changed = $this->reconciler()->reconcile('user@company.com');

        self::assertFalse($changed);
        self::assertSame(['user@company.com'], $this->repository->unreconciledLists());

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('error:boom', $results);
    }

    public function testRetryAfterFailureCompletesTheDiff(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->gateway->failFor = ['user@company.com'];

        $reconciler = $this->reconciler();
        self::assertFalse($reconciler->reconcile('user@company.com'));
        self::assertSame(['user@company.com'], $this->repository->unreconciledLists());

        $this->gateway->failFor = [];
        self::assertTrue($reconciler->reconcile('user@company.com'));
        self::assertSame(['info@company.com'], $this->gateway->aliases['user@company.com']);
        self::assertSame([], $this->repository->unreconciledLists());
    }

    public function testAdoptSeedsRepositoryFromPleskOnlyOnFirstTouch(): void
    {
        $this->gateway->aliases['user@company.com'] = ['info@company.com', 'sales@company.com'];

        self::assertTrue($this->reconciler()->adopt('user@company.com'));
        self::assertFalse($this->reconciler()->adopt('user@company.com'));

        self::assertSame(
            ['info@company.com', 'sales@company.com'],
            $this->repository->activeAliases('user@company.com'),
        );
    }

    public function testAddAfterAdoptKeepsPreExistingAliases(): void
    {
        $this->gateway->aliases['user@company.com'] = ['info@company.com'];

        $this->reconciler()->adopt('user@company.com');
        $this->repository->upsertActive('user@company.com', 'newalias@company.com');
        $this->reconciler()->reconcile('user@company.com');

        self::assertSame(
            ['info@company.com', 'newalias@company.com'],
            $this->gateway->aliases['user@company.com'],
        );
    }

    public function testDryRunComputesDiffWithoutMutating(): void
    {
        $gateway = new RecordingGateway(dryRunMode: true);
        $reconciler = new MailAliasReconciler($this->repository, $this->syncLog, $gateway, new Logger('test'), true);

        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $gateway->aliases['user@company.com'] = ['leaver@company.com'];

        $changed = $reconciler->reconcile('user@company.com');

        self::assertTrue($changed);
        self::assertSame(['leaver@company.com'], $gateway->aliases['user@company.com']);

        $results = array_column($this->syncLog->recent(), 'result');
        self::assertContains('dry-run', $results);
        self::assertSame(['info@company.com'], $this->repository->activeAliases('user@company.com'));
        self::assertSame(['user@company.com'], $this->repository->unreconciledLists());
    }

    private function reconciler(bool $dryRun = false): MailAliasReconciler
    {
        return new MailAliasReconciler($this->repository, $this->syncLog, $this->gateway, new Logger('test'), $dryRun);
    }
}
