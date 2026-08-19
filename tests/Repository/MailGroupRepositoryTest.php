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
}
