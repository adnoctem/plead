<?php

declare(strict_types=1);

namespace App\Tests\Inbox;

use App\Database\Connection;
use App\Inbox\InboxExpiry;
use App\Inbox\InboxProcessor;
use App\Inbox\InboxSettings;
use App\Reconciler\MailGroupReconciler;
use App\Repository\AutoReplyRepository;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use App\Template\AutoReplyRenderer;
use App\Tests\Support\RecordingGateway;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class InboxProcessorTest extends TestCase
{
    private string $base;
    private string $inbox;
    private RecordingGateway $gateway;
    private AutoReplyRepository $autoReplies;
    private MailGroupRepository $forwardings;
    private SyncLogRepository $syncLog;
    private InboxSettings $settings;
    private InboxProcessor $processor;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-inbox-test-'.bin2hex(random_bytes(4));
        $this->inbox = $this->base.'/inbox';
        mkdir($this->inbox, 0o777, true);

        $connection = new Connection($this->base.'/plead.sqlite');
        $this->gateway = new RecordingGateway();
        $this->autoReplies = new AutoReplyRepository($connection, 'fake.local');
        $this->forwardings = new MailGroupRepository($connection, 'fake.local');
        $this->syncLog = new SyncLogRepository($connection, 'fake.local');
        $this->logger = new Logger('test', [new NullHandler()]);

        $this->settings = InboxSettings::fromConfig([
            'watch' => ['inbox' => [
                'paths' => ['root' => $this->inbox],
                'domain' => 'company.com',
                'timezone' => 'Europe/Berlin',
            ]],
        ]) ?? throw new \RuntimeException('inbox settings were not built');

        $this->processor = $this->makeProcessor($this->settings, false);
    }

    public function testSchedulesWithoutDeputyAndArchivesTheFile(): void
    {
        $this->seedMailbox('user@company.com');
        $payload = $this->payload(['email' => 'user@company.com', 'startDate' => '2026-09-17', 'endDate' => '2026-09-18']);
        $this->writeEntry('user.json', $payload);

        self::assertSame(1, $this->processor->process());

        $row = $this->autoReplies->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('scheduled', $row['status']);
        self::assertSame('', $row['deputy_email']);
        self::assertSame('2026-09-17T00:00:00+02:00', $row['start_date']);
        self::assertStringContainsString('nicht weitergeleitet', $row['message']);

        self::assertFileDoesNotExist($this->inbox.'/user.json');
        self::assertFileExists($this->inbox.'/processed/user.json');
        self::assertSame([], $this->forwardings->activeRecipients('user@company.com'));
    }

    public function testSchedulesWithDeputyAndQueuesTheForwarding(): void
    {
        $this->seedMailbox('user@company.com');
        $payload = $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
            'deputyEmail' => 'deputy@company.com',
        ]);
        $this->writeEntry('user.json', $payload);

        self::assertSame(1, $this->processor->process());

        $row = $this->autoReplies->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('deputy@company.com', $row['deputy_email']);
        self::assertStringContainsString('an deputy@company.com weitergeleitet', $row['message']);
        self::assertSame(['deputy@company.com'], $this->forwardings->activeRecipients('user@company.com'));
    }

    public function testExistingForwardingIsAdoptedNotReplaced(): void
    {
        $this->seedMailbox('user@company.com');
        $this->gateway->forwarding['user@company.com'] = ['existing@example.com'];
        $payload = $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
            'deputyEmail' => 'deputy@company.com',
        ]);
        $this->writeEntry('user.json', $payload);

        $this->processor->process();

        self::assertSame(
            ['deputy@company.com', 'existing@example.com'],
            $this->forwardings->activeRecipients('user@company.com'),
        );
    }

    public function testANewEntryWithoutDeputyDropsThePreviousDeputy(): void
    {
        $this->seedMailbox('user@company.com');

        $this->writeEntry('user.json', $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
            'deputyEmail' => 'first@company.com',
        ]));
        $this->processor->process();

        $this->writeEntry('user.json', $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-02',
        ]));
        $this->processor->process();

        self::assertSame([], $this->forwardings->activeRecipients('user@company.com'));
        $row = $this->autoReplies->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('', $row['deputy_email']);
    }

    public function testUnknownMailboxMovesTheFileToFailedWithAnErrorSidecar(): void
    {
        $this->writeEntry('ghost.json', $this->payload([
            'email' => 'ghost@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]));

        self::assertSame(0, $this->processor->process());

        self::assertFileDoesNotExist($this->inbox.'/ghost.json');
        self::assertFileExists($this->inbox.'/failed/ghost.json');
        self::assertFileExists($this->inbox.'/failed/ghost.json.error.txt');
        self::assertStringContainsString(
            'does not exist',
            (string) file_get_contents($this->inbox.'/failed/ghost.json.error.txt'),
        );
        self::assertNull($this->autoReplies->find('ghost@company.com'));
    }

    public function testMalformedJsonMovesTheFileToFailed(): void
    {
        file_put_contents($this->inbox.'/broken.json', '{ not json');

        self::assertSame(0, $this->processor->process());

        self::assertFileExists($this->inbox.'/failed/broken.json');
        self::assertStringContainsString(
            'invalid JSON',
            (string) file_get_contents($this->inbox.'/failed/broken.json.error.txt'),
        );
    }

    public function testUtf8BomIsTolerated(): void
    {
        $this->seedMailbox('user@company.com');
        $this->writeEntry('user.json', $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]), bom: true);

        self::assertSame(1, $this->processor->process());
        self::assertNotNull($this->autoReplies->find('user@company.com'));
        self::assertFileExists($this->inbox.'/processed/user.json');
    }

    public function testNonJsonFilesAreIgnored(): void
    {
        file_put_contents($this->inbox.'/notes.txt', 'ignore me');

        self::assertSame(0, $this->processor->process());
        self::assertFileExists($this->inbox.'/notes.txt');
    }

    public function testAccountMapResolvesSamAccountNameAndIsNotArchived(): void
    {
        $this->seedMailbox('christopher.fischer@example.com');
        file_put_contents(
            $this->inbox.'/ad-account-map.json',
            json_encode(['CFischer' => 'christopher.fischer@example.com'], JSON_THROW_ON_ERROR),
        );
        $this->writeEntry('CFischer.json', $this->payload([
            'samAccountName' => 'CFischer',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]));

        self::assertSame(1, $this->processor->process());

        self::assertNotNull($this->autoReplies->find('christopher.fischer@example.com'));
        self::assertFileExists($this->inbox.'/ad-account-map.json');
        self::assertFileExists($this->inbox.'/processed/CFischer.json');
    }

    public function testAccountMapMissingTheAccountRejectsTheEntry(): void
    {
        file_put_contents(
            $this->inbox.'/ad-account-map.json',
            json_encode(['CFischer' => 'christopher.fischer@example.com'], JSON_THROW_ON_ERROR),
        );
        $this->writeEntry('Ghost.json', $this->payload([
            'samAccountName' => 'Ghost',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]));

        self::assertSame(0, $this->processor->process());

        self::assertFileExists($this->inbox.'/failed/Ghost.json');
        self::assertStringContainsString(
            'not present in ad-account-map.json',
            (string) file_get_contents($this->inbox.'/failed/Ghost.json.error.txt'),
        );
        self::assertNull($this->autoReplies->find('ghost@company.com'));
    }

    public function testMalformedAccountMapIsNotArchived(): void
    {
        file_put_contents($this->inbox.'/ad-account-map.json', '{ not json');
        $this->seedMailbox('user@company.com');
        $this->writeEntry('user.json', $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]));

        self::assertSame(1, $this->processor->process());

        self::assertFileExists($this->inbox.'/ad-account-map.json');
        self::assertFileExists($this->inbox.'/processed/user.json');
    }

    public function testMalformedAccountMapRejectsSamAccountNameEntries(): void
    {
        file_put_contents($this->inbox.'/ad-account-map.json', '{ not json');
        $this->writeEntry('CFischer.json', $this->payload([
            'samAccountName' => 'CFischer',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]));

        self::assertSame(0, $this->processor->process());

        self::assertFileExists($this->inbox.'/ad-account-map.json');
        self::assertFileExists($this->inbox.'/failed/CFischer.json');
        self::assertStringContainsString(
            'ad-account-map.json is invalid',
            (string) file_get_contents($this->inbox.'/failed/CFischer.json.error.txt'),
        );
    }

    public function testCancelDisablesTheReplyAndRemovesTheDeputy(): void
    {
        $this->seedMailbox('user@company.com');
        $this->autoReplies->upsert(
            'user@company.com',
            'Message',
            '2026-09-17T00:00:00+02:00',
            '2026-09-18T23:59:59+02:00',
            '',
            '',
            'deputy@company.com',
        );
        $this->forwardings->upsertActive('user@company.com', 'deputy@company.com');

        $this->writeEntry('cancel.json', $this->payload([
            'action' => 'cancel',
            'email' => 'user@company.com',
        ]));

        self::assertSame(1, $this->processor->process());

        $row = $this->autoReplies->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame([], $this->forwardings->activeRecipients('user@company.com'));
    }

    public function testExpiryRemovesTheDeputyAndDisablesTheRow(): void
    {
        $this->autoReplies->upsert(
            'user@company.com',
            'Message',
            '2020-01-01T00:00:00+01:00',
            '2020-01-05T23:59:59+01:00',
            '',
            '',
            'deputy@company.com',
        );
        $this->forwardings->upsertActive('user@company.com', 'deputy@company.com');

        $expiry = new InboxExpiry($this->autoReplies, $this->forwardings, $this->syncLog, $this->logger, false);

        self::assertSame(1, $expiry->expire(new \DateTimeImmutable('2026-01-01T00:00:00+01:00')));

        $row = $this->autoReplies->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame([], $this->forwardings->activeRecipients('user@company.com'));
        self::assertSame(0, $expiry->expire(new \DateTimeImmutable('2026-01-01T00:00:00+01:00')));
    }

    public function testExpiryIgnoresRunningAndDeputyLessEntries(): void
    {
        $this->autoReplies->upsert(
            'running@company.com',
            'Message',
            '2020-01-01T00:00:00+01:00',
            '2099-01-05T23:59:59+01:00',
            '',
            '',
            'deputy@company.com',
        );
        $this->autoReplies->upsert(
            'plain@company.com',
            'Message',
            '2020-01-01T00:00:00+01:00',
            '2020-01-05T23:59:59+01:00',
        );

        $expiry = new InboxExpiry($this->autoReplies, $this->forwardings, $this->syncLog, $this->logger, false);

        self::assertSame(0, $expiry->expire(new \DateTimeImmutable('2026-01-01T00:00:00+01:00')));
        $running = $this->autoReplies->find('running@company.com');
        self::assertNotNull($running);
        self::assertSame('scheduled', $running['status']);
        $plain = $this->autoReplies->find('plain@company.com');
        self::assertNotNull($plain);
        self::assertSame('scheduled', $plain['status']);
    }

    public function testDryRunKeepsTheFileInPlace(): void
    {
        $this->seedMailbox('user@company.com');
        $processor = $this->makeProcessor($this->settings, true);
        $this->writeEntry('user.json', $this->payload([
            'email' => 'user@company.com',
            'startDate' => '2026-09-17',
            'endDate' => '2026-09-18',
        ]));

        self::assertSame(1, $processor->process());

        self::assertNotNull($this->autoReplies->find('user@company.com'));
        self::assertFileExists($this->inbox.'/user.json');
    }

    private function makeProcessor(InboxSettings $settings, bool $dryRun): InboxProcessor
    {
        $templates = dirname(__DIR__, 2).'/resources/templates';
        $translations = dirname(__DIR__, 2).'/resources/translations';

        return new InboxProcessor(
            $settings,
            $this->autoReplies,
            $this->forwardings,
            new MailGroupReconciler($this->forwardings, $this->syncLog, $this->gateway, $this->logger, $dryRun),
            $this->syncLog,
            $this->gateway,
            new AutoReplyRenderer($templates.'/autoresponder.txt.twig', $translations),
            $this->logger,
            $dryRun,
        );
    }

    private function seedMailbox(string $email): void
    {
        $this->gateway->mailboxes[$email] = [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function payload(array $payload): array
    {
        return ['schemaVersion' => 1] + $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeEntry(string $name, array $payload, bool $bom = false): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        file_put_contents($this->inbox.'/'.$name, ($bom ? "\xEF\xBB\xBF" : '').$json);
    }
}
