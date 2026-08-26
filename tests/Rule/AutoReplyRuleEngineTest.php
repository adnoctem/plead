<?php

declare(strict_types=1);

namespace App\Tests\Rule;

use App\Database\Connection;
use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use App\Rule\AutoReplyRuleEngine;
use App\Template\AutoReplyRenderer;
use App\Template\MjmlConverter;
use App\Tests\Support\FakeBin;
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
    private string $dataFile;

    protected function setUp(): void
    {
        $this->connection = new Connection(sys_get_temp_dir().'/plead-auto-reply-engine-'.bin2hex(random_bytes(4)).'/plead.sqlite');
        $this->repository = new AutoReplyRepository($this->connection, 'fake.local');
        $this->syncLog = new SyncLogRepository($this->connection, 'fake.local');
        $this->engine = $this->makeEngine(false);
        $this->dataFile = sys_get_temp_dir().'/plead-reply-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($this->dataFile, implode("\n", ['firstname: Erika', 'surname: Mustermann']));
    }

    protected function tearDown(): void
    {
        @unlink($this->dataFile);
        DateNormalizer::now();
    }

    public function testRecordsIntentWhenRowMissing(): void
    {
        self::assertTrue($this->engine->apply($this->entry()));

        $row = $this->repository->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('0', (string) $row['reconciled']);
        self::assertSame('2099-01-01T08:00:00+02:00', $row['start_date']);
        self::assertStringContainsString('Erika Mustermann', $row['message']);
        self::assertStringContainsString('Sehr geehrter Kunde', $row['message']);

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

    public function testRecordsIntentWhenDataChanged(): void
    {
        $this->engine->apply($this->entry());
        file_put_contents($this->dataFile, implode("\n", ['firstname: Max', 'surname: Mustermann']));

        self::assertTrue($this->engine->apply($this->entry()));
        $row = $this->repository->find('user@company.com');
        self::assertStringContainsString('Max Mustermann', $row['message']);
        self::assertStringNotContainsString('Erika Mustermann', $row['message']);
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
            ['address' => 'broken@company.com', 'data_file' => '/nonexistent/data.json', 'end_date' => '2099-01-05T18:00:00+02:00'],
        ];

        self::assertSame(1, $this->engine->applyAll($entries));
        self::assertNotNull($this->repository->find('user@company.com'));
        self::assertNull($this->repository->find('broken@company.com'));
    }

    public function testDryRunStillRecordsIntent(): void
    {
        $engine = $this->makeEngine(true);

        self::assertTrue($engine->apply($this->entry()));
        self::assertSame('dry-run', $this->syncLog->all('auto_reply')[0]['result']);
        self::assertNotNull($this->repository->find('user@company.com'));
    }

    public function testDataFileValuesFlowIntoRenderedMessage(): void
    {
        $entry = $this->entry();

        self::assertTrue($this->engine->apply($entry));
        self::assertStringContainsString('Erika Mustermann', $this->repository->find('user@company.com')['message']);
    }

    public function testScheduleDatesWinOverDataFileValues(): void
    {
        $dataFile = sys_get_temp_dir().'/plead-reply-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($dataFile, json_encode(['startDate' => '2099-09-09', 'endDate' => '2099-09-19'], JSON_THROW_ON_ERROR));

        try {
            $entry = $this->entry();
            $entry['data_file'] = $dataFile;

            self::assertTrue($this->engine->apply($entry));
            $message = $this->repository->find('user@company.com')['message'];
            self::assertStringContainsString('01.01.2099', $message);
            self::assertStringContainsString('05.01.2099', $message);
            self::assertStringNotContainsString('2099-09-09', $message);
            self::assertStringNotContainsString('2099-09-19', $message);
        } finally {
            @unlink($dataFile);
        }
    }

    public function testNoopWhenDataFileDidNotChangeMessage(): void
    {
        $entry = $this->entry();

        self::assertTrue($this->engine->apply($entry));

        // A second pass with the same data file must not re-record.
        self::assertFalse($this->engine->apply($entry));
        self::assertCount(1, $this->syncLog->all('auto_reply'));
    }

    public function testEntryInclusiveAppliesToRenderedMessage(): void
    {
        $entry = $this->entry();
        $entry['inclusive'] = true;

        self::assertTrue($this->engine->apply($entry));
        self::assertStringContainsString('einschließlich', $this->repository->find('user@company.com')['message']);

        $entry['inclusive'] = false;
        self::assertTrue($this->engine->apply($entry));
        self::assertStringNotContainsString('einschließlich', $this->repository->find('user@company.com')['message']);
    }

    public function testSubjectAndFormatStoredOnIntent(): void
    {
        $entry = $this->entry();
        $entry['subject'] = 'Automatische Antwort';
        $entry['format'] = 'html';

        self::assertTrue($this->engine->apply($entry));
        $row = $this->repository->find('user@company.com');
        self::assertSame('Automatische Antwort', $row['subject']);
        self::assertSame('html', $row['format']);

        // No-op when nothing changed, including subject/format.
        self::assertFalse($this->engine->apply($entry));
    }

    public function testEntryLanguageRendersOnlyThatLanguage(): void
    {
        // Single-language templates (e.g. MJML) honor the entry language;
        // the txt template renders every language regardless.
        $fakeBinary = FakeBin::install(
            dirname(__DIR__).'/Support/fake-bin/mjml.php',
            sys_get_temp_dir().'/plead-fake-mjml-'.bin2hex(random_bytes(4)),
        );
        $engine = new AutoReplyRuleEngine(
            $this->repository,
            $this->syncLog,
            new AutoReplyRenderer(
                __DIR__.'/../../resources/templates/autoresponder.mjml.twig',
                __DIR__.'/../../resources/translations',
                null,
                new MjmlConverter($fakeBinary),
            ),
            new Logger('test'),
            false,
        );
        $entry = $this->entry();
        $entry['language'] = 'en';

        self::assertTrue($engine->apply($entry));
        $message = $this->repository->find('user@company.com')['message'];
        self::assertStringContainsString('Dear Customer', $message);
        self::assertStringNotContainsString('Sehr geehrter', $message);
    }

    private function makeEngine(bool $dryRun): AutoReplyRuleEngine
    {
        return new AutoReplyRuleEngine(
            $this->repository,
            $this->syncLog,
            new AutoReplyRenderer(__DIR__.'/../../resources/templates/autoresponder.txt.twig', __DIR__.'/../../resources/translations'),
            new Logger('test'),
            $dryRun,
        );
    }

    /** @return array<string, mixed> */
    private function entry(?string $start = '2099-01-01T08:00:00+02:00'): array
    {
        return [
            'address' => 'user@company.com',
            'data_file' => $this->dataFile,
            'start_date' => $start,
            'end_date' => '2099-01-05T18:00:00+02:00',
        ];
    }
}
