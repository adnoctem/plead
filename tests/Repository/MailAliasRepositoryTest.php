<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Database\Connection;
use App\Repository\MailAliasRepository;
use PHPUnit\Framework\TestCase;

final class MailAliasRepositoryTest extends TestCase
{
    private MailAliasRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new MailAliasRepository(
            new Connection(sys_get_temp_dir() . '/plead-alias-repo-test-' . bin2hex(random_bytes(4)) . '/plead.sqlite'),
        );
    }

    public function testUpsertActiveAddsAndReactivates(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->repository->remove('user@company.com', 'info@company.com');
        self::assertSame([], $this->repository->activeAliases('user@company.com'));

        $this->repository->upsertActive('user@company.com', 'info@company.com');
        self::assertSame(['info@company.com'], $this->repository->activeAliases('user@company.com'));
    }

    public function testRemoveIsSoftDelete(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->repository->remove('user@company.com', 'info@company.com');

        self::assertSame([], $this->repository->activeAliases('user@company.com'));

        $history = $this->repository->history('user@company.com');
        self::assertCount(1, $history);
        self::assertNotNull($history[0]['removed_at']);
    }

    public function testActiveAliasesAreSorted(): void
    {
        $this->repository->upsertActive('user@company.com', 'sales@company.com');
        $this->repository->upsertActive('user@company.com', 'info@company.com');

        self::assertSame(['info@company.com', 'sales@company.com'], $this->repository->activeAliases('user@company.com'));
    }

    public function testManagedListsReturnsDistinctEmails(): void
    {
        $this->repository->upsertActive('user@company.com', 'a@company.com');
        $this->repository->upsertActive('sales@company.com', 'b@company.com');
        $this->repository->remove('user@company.com', 'c@company.com');

        self::assertSame(['sales@company.com', 'user@company.com'], $this->repository->managedLists());
    }

    public function testHistoryKeepsRemovedRecords(): void
    {
        $this->repository->upsertActive('user@company.com', 'leaver@company.com');
        $this->repository->remove('user@company.com', 'leaver@company.com');

        $removed = array_filter(
            $this->repository->history('user@company.com'),
            static fn (array $row): bool => null !== $row['removed_at'],
        );

        self::assertCount(1, $removed);
    }

    public function testChangesFlagMailboxesAsUnreconciled(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->repository->markListReconciled('user@company.com');

        self::assertSame([], $this->repository->unreconciledLists());

        $this->repository->upsertActive('user@company.com', 'sales@company.com');
        self::assertSame(['user@company.com'], $this->repository->unreconciledLists());

        $this->repository->markListReconciled('user@company.com');
        self::assertSame([], $this->repository->unreconciledLists());
        self::assertSame('1', (string) $this->repository->history('user@company.com')[0]['reconciled']);
    }

    public function testIndexAggregatesCountsAndPendingMarker(): void
    {
        $this->repository->upsertActive('user@company.com', 'info@company.com');
        $this->repository->upsertActive('user@company.com', 'sales@company.com');
        $this->repository->remove('user@company.com', 'leaver@company.com');
        $this->repository->upsertActive('other@company.com', 'carol@company.com');
        $this->repository->markListReconciled('other@company.com');

        $rows = $this->repository->index();

        self::assertCount(2, $rows);
        $user = $rows[array_search('user@company.com', array_column($rows, 'email'), true)];
        self::assertSame(2, (int) $user['active_count']);
        self::assertSame(1, (int) $user['removed_count']);
        self::assertSame(3, (int) $user['pending_count']);

        $other = $rows[array_search('other@company.com', array_column($rows, 'email'), true)];
        self::assertSame(0, (int) $other['pending_count']);
    }
}
