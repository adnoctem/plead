<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Database\Connection;
use App\Repository\MailGroupRepository;
use PHPUnit\Framework\TestCase;

final class MailGroupRepositoryTest extends TestCase
{
    private MailGroupRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new MailGroupRepository(
            new Connection(sys_get_temp_dir() . '/plead-mail-repo-test-' . bin2hex(random_bytes(4)) . '/plead.sqlite'),
        );
    }

    public function testUpsertActiveAddsAndReactivates(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->repository->remove('group@company.com', 'alice@company.com');
        self::assertSame([], $this->repository->activeRecipients('group@company.com'));

        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        self::assertSame(['alice@company.com'], $this->repository->activeRecipients('group@company.com'));
    }

    public function testRemoveIsSoftDelete(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->repository->remove('group@company.com', 'alice@company.com');

        self::assertSame([], $this->repository->activeRecipients('group@company.com'));

        $history = $this->repository->history('group@company.com');
        self::assertCount(1, $history);
        self::assertNotNull($history[0]['removed_at']);
    }

    public function testActiveRecipientsAreSorted(): void
    {
        $this->repository->upsertActive('group@company.com', 'bob@company.com');
        $this->repository->upsertActive('group@company.com', 'alice@company.com');

        self::assertSame(['alice@company.com', 'bob@company.com'], $this->repository->activeRecipients('group@company.com'));
    }

    public function testManagedListsReturnsDistinctEmails(): void
    {
        $this->repository->upsertActive('group@company.com', 'a@company.com');
        $this->repository->upsertActive('all@company.com', 'b@company.com');
        $this->repository->remove('group@company.com', 'c@company.com');

        self::assertSame(['all@company.com', 'group@company.com'], $this->repository->managedLists());
    }

    public function testHistoryKeepsRemovedRecords(): void
    {
        $this->repository->upsertActive('group@company.com', 'leaver@company.com');
        $this->repository->remove('group@company.com', 'leaver@company.com');

        $removed = array_filter(
            $this->repository->history('group@company.com'),
            static fn (array $row): bool => null !== $row['removed_at'],
        );

        self::assertCount(1, $removed);
    }

    public function testChangesFlagRecipientsAsUnreconciled(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->repository->markListReconciled('group@company.com');

        self::assertSame([], $this->repository->unreconciledLists());

        $this->repository->upsertActive('group@company.com', 'bob@company.com');
        self::assertSame(['group@company.com'], $this->repository->unreconciledLists());

        $this->repository->markListReconciled('group@company.com');
        self::assertSame([], $this->repository->unreconciledLists());
        self::assertSame('1', (string) $this->repository->history('group@company.com')[0]['reconciled']);
    }

    public function testIndexAggregatesCountsAndPendingMarker(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->repository->upsertActive('group@company.com', 'bob@company.com');
        $this->repository->remove('group@company.com', 'leaver@company.com');
        $this->repository->upsertActive('all@company.com', 'carol@company.com');
        $this->repository->markListReconciled('all@company.com');

        $rows = $this->repository->index();

        self::assertCount(2, $rows);
        $group = $rows[array_search('group@company.com', array_column($rows, 'list_email'), true)];
        self::assertSame(2, (int) $group['active_count']);
        self::assertSame(1, (int) $group['removed_count']);
        self::assertSame(3, (int) $group['pending_count']);

        $all = $rows[array_search('all@company.com', array_column($rows, 'list_email'), true)];
        self::assertSame(0, (int) $all['pending_count']);
    }

    public function testAddressIndexListsMemberships(): void
    {
        $this->repository->upsertActive('group@company.com', 'alice@company.com');
        $this->repository->upsertActive('all@company.com', 'alice@company.com');
        $this->repository->upsertActive('group@company.com', 'bob@company.com');

        $addresses = array_column($this->repository->addressIndex(), 'recipient_email');

        self::assertSame(['alice@company.com', 'alice@company.com', 'bob@company.com'], $addresses);
        self::assertSame(['all@company.com', 'group@company.com'], array_column($this->repository->listsOf('alice@company.com'), 'list_email'));
    }
}
