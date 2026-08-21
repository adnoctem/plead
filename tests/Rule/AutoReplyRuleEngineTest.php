<?php

declare(strict_types=1);

namespace App\Tests\Rule;

use App\Database\Connection;
use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use App\Rule\AutoReplyRuleEngine;
use App\Template\AutoReplyRenderer;
use App\Util\DateNormalizer;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class AutoReplyRuleEngineTest extends TestCase
{
    private Connection $connection;
    private AutoReplyRepository $repository;
    private SyncLogRepository $syncLog;
    private AutoReplyRuleEngine $engine;
    private string $messageFile;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir().'/plead-auto-reply-engine-'.bin2hex(random_bytes(4)).'/plead.sqlite');
        $this->repository = new AutoReplyRepository($this->connection, 'fake.local');
        $this->syncLog = new SyncLogRepository($this->connection, 'fake.local');
        $this->engine = new AutoReplyRuleEngine(
            $this->repository,
            $this->syncLog,
            new AutoReplyRenderer(__DIR__.'/../../templates/auto-reply.txt.twig'),
            new Logger('test'),
            false,
        );
        $this->messageFile = sys_get_temp_dir().'/plead-reply-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($this->messageFile, 'Bin im Urlaub');
    }

    protected function tearDown(): void
    {
        @unlink($this->messageFile);
        DateNormalizer::now();
    }

    public function testRecordsIntentWhenRowMissing(): void
    {
        self::assertTrue($this->engine->apply($this->entry()));

        $row = $this->repository->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('0', (string) $row['reconciled']);
        self::assertSame('2099-01-01T08:00:00+02:00', $row['start_date']);
        self::assertStringContainsString('Bin im Urlaub', $row['message']);

        $entries = $this->syncLog->all('auto_reply');
        self::assertCount(1, $entries);
        self::assertSame('set', $entries[0]['action']);
    }

    public function testNoopWhenRowMatches(): void
    {
        $this->engine->apply($this->entry());

        self::assertFalse($this->engine->apply($this->entry()));
        self::assertCount(1, $this->syncLog->all('auto_reply'));
    }

    public function testRecordsIntentWhenMessageChanged(): void
    {
        $this->engine->apply($this->entry());
        file_put_contents($this->messageFile, 'Neue Nachricht');

        self::assertTrue($this->engine->apply($this->entry()));
        $row = $this->repository->find('user@company.com');
        self::assertStringContainsString('Neue Nachricht', $row['message']);
    }

    public function testWithoutStartDateKeepsRecordedStart(): void
    {
        $entry = $this->entry();
        unset($entry['start_date']);

        self::assertTrue($this->engine->apply($entry));
        $firstStart = $this->repository->find('user@company.com')['start_date'];

        // A second pass must not re-stamp "now" (that would re-flag the row
        // dirty forever) - the recorded start date is kept.
        self::assertFalse($this->engine->apply($entry));
        self::assertSame($firstStart, $this->repository->find('user@company.com')['start_date']);
    }

    public function testApplyAllSkipsBrokenEntries(): void
    {
        $entries = [
            $this->entry(),
            ['address' => 'broken@company.com', 'message_file' => '/nonexistent/file.txt', 'end_date' => '2099-01-05T18:00:00+02:00'],
        ];

        self::assertSame(1, $this->engine->applyAll($entries));
        self::assertNotNull($this->repository->find('user@company.com'));
        self::assertNull($this->repository->find('broken@company.com'));
    }

    public function testDryRunStillRecordsIntent(): void
    {
        $engine = new AutoReplyRuleEngine(
            $this->repository,
            $this->syncLog,
            new AutoReplyRenderer(__DIR__.'/../../templates/auto-reply.txt.twig'),
            new Logger('test'),
            true,
        );

        self::assertTrue($engine->apply($this->entry()));
        self::assertSame('dry-run', $this->syncLog->all('auto_reply')[0]['result']);
        self::assertNotNull($this->repository->find('user@company.com'));
    }

    /** @return array<string, mixed> */
    private function entry(?string $start = '2099-01-01T08:00:00+02:00'): array
    {
        return [
            'address' => 'user@company.com',
            'message_file' => $this->messageFile,
            'start_date' => $start,
            'end_date' => '2099-01-05T18:00:00+02:00',
        ];
    }
}
