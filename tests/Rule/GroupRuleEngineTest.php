<?php

declare(strict_types=1);

namespace App\Tests\Rule;

use App\Database\Connection;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use App\Rule\GroupRule;
use App\Rule\GroupRuleEngine;
use App\Tests\Support\RecordingGateway;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class GroupRuleEngineTest extends TestCase
{
    private Connection $connection;
    private MailGroupRepository $repository;
    private SyncLogRepository $syncLog;
    private RecordingGateway $gateway;
    private GroupRuleEngine $engine;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir().'/plead-rule-engine-'.bin2hex(random_bytes(4)).'/plead.sqlite');
        $this->repository = new MailGroupRepository($this->connection, 'fake.local');
        $this->syncLog = new SyncLogRepository($this->connection, 'fake.local');
        $this->gateway = new RecordingGateway();
        $this->engine = new GroupRuleEngine(
            $this->repository,
            $this->syncLog,
            $this->gateway,
            new Logger('test'),
            false,
        );
    }

    public function testComputesRecipientsFromLiveAddressesAndRecordsIntents(): void
    {
        $this->seedAddresses();

        self::assertTrue($this->engine->apply($this->patternRule()));

        // info/noreply excluded; the list itself is never its own recipient.
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->repository->activeRecipients('all@company.com'));
        self::assertSame(['all@company.com'], $this->repository->unreconciledLists());

        $entries = $this->syncLog->all('mail_group');
        self::assertCount(1, $entries);
        self::assertSame('set', $entries[0]['action']);
        self::assertSame('ok', $entries[0]['result']);
    }

    public function testNoopWhenDesiredStateUnchanged(): void
    {
        $this->seedAddresses();
        $this->engine->apply($this->patternRule());

        self::assertFalse($this->engine->apply($this->patternRule()));
        self::assertCount(1, $this->syncLog->all('mail_group'));
    }

    public function testPurgesRecipientsThatNoLongerMatch(): void
    {
        $this->seedAddresses();
        $this->repository->upsertActive('all@company.com', 'pbx@company.com');

        $this->engine->apply($this->patternRule());

        $history = $this->repository->history('all@company.com');
        $pbx = $history[array_search('pbx@company.com', array_column($history, 'recipient_email'), true)];
        self::assertNotNull($pbx['removed_at']);
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->repository->activeRecipients('all@company.com'));
    }

    public function testStaticRecipientsRule(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'team@company.com',
            'recipients' => ['alice@company.com', 'bob@company.com'],
        ]);

        self::assertTrue($this->engine->apply($rule));
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->repository->activeRecipients('team@company.com'));
    }

    public function testStaticRuleSecondApplyIsNoop(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'team@company.com',
            'recipients' => ['bob@company.com', 'alice@company.com'],
        ]);

        self::assertTrue($this->engine->apply($rule));
        self::assertFalse($this->engine->apply($rule));
        self::assertCount(1, $this->syncLog->all('mail_group'));
    }

    public function testManualRecipientsAppendToPatternFilteredSet(): void
    {
        $this->seedAddresses();
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '^(info|noreply)@',
            'recipients' => ['consultant@external.com', 'info@company.com', 'alice@company.com'],
        ]);

        self::assertTrue($this->engine->apply($rule));

        // Pattern-derived set (minus the list itself) plus the manual
        // recipients, deduplicated and sorted. Manual entries win over the
        // exclusion pattern: info@company.com is listed explicitly, so it is
        // appended despite matching the pattern.
        self::assertSame([
            'alice@company.com',
            'bob@company.com',
            'consultant@external.com',
            'info@company.com',
        ], $this->repository->activeRecipients('all@company.com'));
    }

    public function testApplyAllCountsChangedLists(): void
    {
        $this->seedAddresses();
        $this->repository->upsertActive('team@company.com', 'alice@company.com');

        $entries = [
            [
                'address' => 'all@company.com',
                'pattern' => '^(info)@',
            ],
            [
                'address' => 'team@company.com',
                'recipients' => ['alice@company.com', 'bob@company.com'],
            ],
        ];

        self::assertSame(2, $this->engine->applyAll($entries));
        self::assertSame(['alice@company.com', 'bob@company.com', 'noreply@company.com'], $this->repository->activeRecipients('all@company.com'));
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->repository->activeRecipients('team@company.com'));
    }

    public function testDryRunStillRecordsIntents(): void
    {
        $this->seedAddresses();
        $engine = new GroupRuleEngine(
            $this->repository,
            $this->syncLog,
            $this->gateway,
            new Logger('test'),
            true,
        );

        self::assertTrue($engine->apply($this->patternRule()));
        self::assertSame(['alice@company.com', 'bob@company.com'], $this->repository->activeRecipients('all@company.com'));
        self::assertSame('dry-run', $this->syncLog->all('mail_group')[0]['result']);
    }

    private function patternRule(string $pattern = '^(info|noreply)@'): GroupRule
    {
        return GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => $pattern,
        ]);
    }

    private function seedAddresses(): void
    {
        $this->gateway->domains = [['id' => 1, 'name' => 'company.com']];
        $this->gateway->mailnames = [
            ['id' => 10, 'name' => 'alice', 'description' => ''],
            ['id' => 11, 'name' => 'bob', 'description' => ''],
            ['id' => 12, 'name' => 'info', 'description' => ''],
            ['id' => 13, 'name' => 'noreply', 'description' => ''],
            ['id' => 14, 'name' => 'all', 'description' => ''],
        ];
    }
}
