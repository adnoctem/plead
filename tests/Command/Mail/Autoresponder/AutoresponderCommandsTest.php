<?php

declare(strict_types=1);

namespace App\Tests\Command\Mail\Autoresponder;

use App\Application\RuntimeContext;
use App\Command\AbstractPleadCommand;
use App\Command\Mail\Autoresponder\AutoresponderGetCommand;
use App\Command\Mail\Autoresponder\AutoresponderListCommand;
use App\Command\Mail\Autoresponder\AutoresponderSetCommand;
use App\Config\ConfigFile;
use App\Config\PathProvider\PathProviderInterface;
use App\Template\MjmlConverter;
use App\Tests\Support\RecordingGateway;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversNothing]
final class AutoresponderCommandsTest extends TestCase
{
    private string $base;
    private RecordingGateway $gateway;
    private RuntimeContext $context;
    private PathProviderInterface $paths;
    private string $fakeMjmlBinary;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-set-test-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/config', 0o777, true);
        mkdir($this->base.'/data', 0o777, true);
        file_put_contents(
            $this->base.'/config/plead.yaml',
            "servers:\n    - host: fake.local\n      secret_key: test-key\n",
        );
        $this->paths = new class($this->base) implements PathProviderInterface {
            public function __construct(private readonly string $base) {}

            public function configHome(): string
            {
                return $this->base.'/config';
            }

            public function configDirs(): array
            {
                return [$this->base.'/config'];
            }

            public function configPaths(): array
            {
                return [$this->base.'/config/plead.yaml'];
            }

            public function dataHome(): string
            {
                return $this->base.'/data';
            }

            public function dataDir(): string
            {
                return $this->base.'/data';
            }

            public function cacheHome(): string
            {
                return $this->base.'/cache';
            }

            public function logFile(): string
            {
                return $this->base.'/data/plead.log';
            }
        };

        $this->gateway = new RecordingGateway();
        // A pass-through "mjml" binary: wraps the input in HTML so the
        // conversion pipeline is exercised without requiring the real npm
        // binary in CI.
        $this->fakeMjmlBinary = $this->base.'/fake-mjml';
        file_put_contents($this->fakeMjmlBinary, "#!/bin/sh\nprintf '<!doctype html><html><body>\\n'\ncat\nprintf '\\n</body></html>'\n");
        chmod($this->fakeMjmlBinary, 0o755);
        $this->context = new RuntimeContext(
            $this->paths,
            null,
            false,
            null,
            0,
            gateway: $this->gateway,
            mjmlConverter: new MjmlConverter($this->fakeMjmlBinary),
        );
    }

    public function testSchedulesFutureStartWithoutApplying(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('scheduled', $tester->getDisplay());
        self::assertFalse($this->gateway->wasCalledWith('user@company.com'));
        self::assertSame('0', (string) $this->context->autoReplyRepository()->find('user@company.com')['reconciled']);
    }

    public function testImmediateStartAppliesThroughReconciler(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue($this->gateway->wasCalledWith('user@company.com'));
        self::assertStringContainsString('applied', $tester->getDisplay());
        self::assertSame('1', (string) $this->context->autoReplyRepository()->find('user@company.com')['reconciled']);
    }

    public function testRejectsEndDateBeforeStartDate(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-10T08:00:00+02:00',
            '--end-date' => '2099-01-01T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('end-date must be after start-date', $tester->getDisplay());
    }

    public function testGatewayFailureStaysPendingForWatcher(): void
    {
        $this->gateway->failFor = ['user@company.com'];

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('could not be applied to Plesk yet', $tester->getDisplay());
        self::assertSame('0', (string) $this->context->autoReplyRepository()->find('user@company.com')['reconciled']);
    }

    public function testDisableKeepsRowAndDisablesOnPlesk(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--enabled' => 'false']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertFalse($this->gateway->autoresponders['user@company.com']);

        // The row stays: status + reconcile state carry the audit trail.
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame('1', (string) $row['reconciled']);
    }

    public function testDisableFailureStaysPendingForWatcher(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);
        $this->gateway->failFor = ['user@company.com'];

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--enabled' => 'false']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('stays pending', $tester->getDisplay());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertSame('disabled', $row['status']);
        self::assertSame('0', (string) $row['reconciled']);
    }

    public function testShowLocalDisplaysDesiredState(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        $tester = $this->tester(new AutoresponderGetCommand());
        $tester->execute(['email' => 'user@company.com', '--local' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Status:    scheduled', $tester->getDisplay());
    }

    public function testListLocalShowsAllEntries(): void
    {
        $this->tester(new AutoresponderSetCommand())->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        $tester = $this->tester(new AutoresponderListCommand());
        $tester->execute(['--local' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('user@company.com', $tester->getDisplay());
    }

    public function testListLiveShowsOnlyEnabledOnServer(): void
    {
        $this->gateway->mailnames = [
            ['id' => 1, 'name' => 'on', 'description' => ''],
            ['id' => 2, 'name' => 'off', 'description' => ''],
        ];
        $this->gateway->autoresponders = [
            'on@company.com' => true,
            'off@company.com' => false,
        ];

        $tester = $this->tester(new AutoresponderListCommand());
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('on@company.com', $tester->getDisplay());
        self::assertStringNotContainsString('off@company.com', $tester->getDisplay());
    }

    public function testDryRunReportsWouldApply(): void
    {
        $context = new RuntimeContext($this->paths, null, true, null, 0, gateway: new RecordingGateway());
        $command = new AutoresponderSetCommand();
        $command->setContext($context);

        $tester = new CommandTester($command);
        $tester->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('would be applied (dry-run)', $tester->getDisplay());
    }

    public function testSetFallsBackToConfigEntry(): void
    {
        $this->configWithAutoresponder();

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertNotNull($row);
        self::assertSame('0', (string) $row['reconciled']);
        self::assertSame('2099-01-01T08:00:00+02:00', $row['start_date']);
    }

    public function testDataFileInjectsJsonValuesIntoMessage(): void
    {
        $dataFile = $this->base.'/data.json';
        file_put_contents($dataFile, json_encode([
            'firstname' => 'Max',
            'surname' => 'Mustermann',
            'phonenumber' => '+49 30 123456',
            'department' => 'Vertrieb',
            'email' => 'vertrieb@example.com',
            'inclusive' => true,
        ], JSON_THROW_ON_ERROR));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('Max Mustermann', $row['message']);
        self::assertStringContainsString('Vertrieb', $row['message']);
        self::assertStringContainsString('vertrieb@example.com', $row['message']);
        self::assertStringContainsString('vom 01.01.2099 bis einschließlich 05.01.2099', $row['message']);
    }

    public function testDataFileInjectsYamlValuesIntoMessage(): void
    {
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", [
            'firstname: Max',
            'surname: Mustermann',
            'phonenumber: "+49 30 123456"',
            'department: Vertrieb',
            'email: vertrieb@example.com',
            'inclusive: true',
        ]));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('Max Mustermann', $row['message']);
        self::assertStringContainsString('Vertrieb', $row['message']);
    }

    public function testDataFileScheduleDatesWinOverDataFileValues(): void
    {
        $dataFile = $this->base.'/data.json';
        file_put_contents($dataFile, json_encode(['startDate' => '2099-09-09', 'endDate' => '2099-09-19'], JSON_THROW_ON_ERROR));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('vom 01.01.2099 bis', $row['message']);
        self::assertStringContainsString('05.01.2099 in Urlaub', $row['message']);
        self::assertStringNotContainsString('2099-09-09', $row['message']);
        self::assertStringNotContainsString('2099-09-19', $row['message']);
    }

    public function testMissingDataFileFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $this->base.'/does-not-exist.json',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Cannot read data file', $tester->getDisplay());
    }

    public function testPreviewPrintsRenderedMessageWithoutSideEffects(): void
    {
        $dataFile = $this->base.'/data.json';
        file_put_contents($dataFile, json_encode([
            'firstname' => 'Max',
            'surname' => 'Mustermann',
            'phonenumber' => '+49 30 123456',
            'department' => 'Vertrieb',
            'email' => 'vertrieb@example.com',
            'inclusive' => true,
        ], JSON_THROW_ON_ERROR));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--preview' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Max Mustermann', $tester->getDisplay());
        self::assertStringContainsString('Vertrieb', $tester->getDisplay());
        self::assertStringContainsString('vom 01.01.2099 bis einschließlich 05.01.2099', $tester->getDisplay());
        self::assertNull($this->context->autoReplyRepository()->find('user@company.com'));
        self::assertCount(0, $this->context->syncLogRepository()->all('auto_reply'));
        self::assertFalse($this->gateway->wasCalledWith('user@company.com'));
    }

    public function testPreviewFallsBackToConfigEntry(): void
    {
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", ['firstname: Max', 'surname: Mustermann']));
        $this->configWithAutoresponder($dataFile);

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--preview' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Max Mustermann', $tester->getDisplay());
        self::assertNull($this->context->autoReplyRepository()->find('user@company.com'));
    }

    public function testPreviewWithoutAnythingFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--preview' => true]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('configure a mail.autoresponder entry', $tester->getDisplay());
    }

    public function testPreviewRejectsEndBeforeStart(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-10T08:00:00+02:00',
            '--end-date' => '2099-01-01T08:00:00+02:00',
            '--preview' => true,
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('end-date must be after start-date', $tester->getDisplay());
    }

    public function testInclusiveFlagRendersInclusiveVariant(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--inclusive' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('bis einschließlich 05.01.2099', $row['message']);
        self::assertStringNotContainsString('through', $row['message']);
        self::assertStringNotContainsString('until', $row['message']);
    }

    public function testNoInclusiveOverridesDataFile(): void
    {
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", ['firstname: Max', 'inclusive: true']));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--no-inclusive' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('bis 05.01.2099', $row['message']);
        self::assertStringNotContainsString('einschließlich', $row['message']);
        self::assertStringNotContainsString('through', $row['message']);
        self::assertStringNotContainsString('until', $row['message']);
    }

    public function testConfigEntryInclusiveAppliesOnFallback(): void
    {
        $this->configWithAutoresponder(null, true);

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('bis einschließlich 05.01.2099', $row['message']);
    }

    public function testDataFileNestedDepartmentTranslatesPerLanguage(): void
    {
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", [
            'firstname: Max',
            'surname: Mustermann',
            'department:',
            '    de: Buchhaltung',
            '    en: accounting',
        ]));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('unsere Buchhaltung', $row['message']);

        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--language' => 'en',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('our accounting department', $row['message']);
    }

    public function testSetWithWriteConfigPersistsInclusive(): void
    {
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new AutoresponderSetCommand(), $context);
        $tester->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--inclusive' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('written to', $tester->getDisplay());

        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertSame([
            [
                'address' => 'user@company.com',
                'end_date' => '2099-01-05T08:00:00+02:00',
                'inclusive' => true,
                'start_date' => '2099-01-01T08:00:00+02:00',
            ],
        ], $raw['mail']['autoresponder']);
        $this->context->configLoader()->load($this->base.'/config/plead.yaml');
    }

    public function testInvalidJsonDataFileFails(): void
    {
        $dataFile = $this->base.'/broken.json';
        file_put_contents($dataFile, '{not json');

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unable to render the auto-reply message', $tester->getDisplay());
    }

    public function testSetFallsBackToConfigEntryWithDataFile(): void
    {
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", ['firstname: Max', 'surname: Mustermann']));
        $this->configWithAutoresponder($dataFile);

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('Max Mustermann', $row['message']);
    }

    public function testPartialCliRunBorrowsMessageFileFromConfigEntry(): void
    {
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", ['firstname: Max', 'surname: Mustermann']));
        $this->configWithAutoresponder($dataFile);

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com', '--end-date' => '2099-01-07T08:00:00+02:00']);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('Max Mustermann', $row['message']);
        self::assertSame('2099-01-07T08:00:00+02:00', $row['end_date']);
    }

    public function testSetWithoutOptionsAndConfigFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('configure a mail.autoresponder entry', $tester->getDisplay());
    }

    public function testSetWithWriteConfigPersistsEntry(): void
    {
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new AutoresponderSetCommand(), $context);
        $tester->execute([
            'email' => 'user@company.com',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('written to', $tester->getDisplay());

        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertSame([
            [
                'address' => 'user@company.com',
                'end_date' => '2099-01-05T08:00:00+02:00',
                'start_date' => '2099-01-01T08:00:00+02:00',
            ],
        ], $raw['mail']['autoresponder']);
        $this->context->configLoader()->load($this->base.'/config/plead.yaml');
    }

    public function testSetWithWriteConfigPersistsDataFile(): void
    {
        $dataFile = $this->base.'/data.json';
        file_put_contents($dataFile, '{"firstname": "Max"}');
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new AutoresponderSetCommand(), $context);
        $tester->execute([
            'email' => 'user@company.com',
            '--data-file' => $dataFile,
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('written to', $tester->getDisplay());

        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertSame([
            [
                'address' => 'user@company.com',
                'data_file' => $dataFile,
                'end_date' => '2099-01-05T08:00:00+02:00',
                'start_date' => '2099-01-01T08:00:00+02:00',
            ],
        ], $raw['mail']['autoresponder']);
        $this->context->configLoader()->load($this->base.'/config/plead.yaml');
    }

    public function testDisableWithWriteConfigRemovesEntry(): void
    {
        $this->configWithAutoresponder();
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new AutoresponderSetCommand(), $context);
        $tester->execute(['email' => 'user@company.com', '--enabled' => 'false']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('removed from', $tester->getDisplay());

        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertArrayNotHasKey('autoresponder', $raw['mail'] ?? []);
    }

    public function testTemplateOverrideRendersMjml(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--preview' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        // The MJML pipeline converts the rendered markup to HTML.
        self::assertStringContainsString('<!doctype html>', $display);
        self::assertStringContainsString('<mjml background-color="#0B0B0F">', $display);
        self::assertStringContainsString('mj-body background-color="#0B0B0F" width="650px"', $display);
        self::assertStringContainsString('.aa-btn:hover a { background: #FFA31A !important; }', $display);
        // Responsive: mobile media query shrinks gaps and tightens the card.
        self::assertStringContainsString('@media only screen and (max-width:650px)', $display);
        self::assertStringContainsString('.aa-gap td, .aa-gap div { height: 56px !important; line-height: 56px !important; }', $display);
        self::assertStringContainsString('<mj-spacer css-class="aa-gap" height="196px"></mj-spacer>', $display);
        // Single language per view (German default), buttons side by side.
        self::assertStringContainsString('Sehr geehrter Kunde / Geschäftspartner,', $display);
        self::assertStringNotContainsString('Dear Customer', $display);
        self::assertSame(2, substr_count($display, '<mj-column width="42%"'));
        // Values are clickable in the sentence and mirrored by the buttons.
        self::assertSame(2, substr_count($display, 'href="tel:'));
        self::assertSame(2, substr_count($display, 'href="mailto:'));
        self::assertStringContainsString('Anrufen', $display);
        self::assertStringContainsString('E-Mail schreiben', $display);
        // Footer with social links and copyright.
        self::assertStringContainsString('github.com/adnoctem', $display);
        self::assertStringContainsString('adnoctem.co', $display);
        self::assertStringContainsString('Ad Noctem Collective', $display);
        // Bold dates inside the period sentence (spans, not nested mj-text).
        self::assertStringContainsString('<span style="font-weight:600">01.01.2099</span>', $display);
        self::assertStringNotContainsString('<mj-text style="font-weight:600">', $display);
    }

    public function testLanguageFlagRendersSingleLanguage(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--language' => 'en',
            '--preview' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dear Customer / Business Partner,', $display);
        self::assertStringContainsString('Call us', $display);
        self::assertStringNotContainsString('Sehr geehrter', $display);
        self::assertStringNotContainsString('Anrufen', $display);
    }

    public function testUnknownLanguageFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--language' => 'fr',
            '--preview' => true,
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Unknown language "fr"', $tester->getDisplay());
        self::assertStringContainsString('de, en', $tester->getDisplay());
    }

    public function testConfigEntryLanguageAppliesOnFallback(): void
    {
        file_put_contents($this->base.'/config/plead.yaml', implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'mail:',
            '    autoresponder:',
            '        - address: user@company.com',
            '          language: en',
            '          start_date: "2099-01-01T08:00:00+02:00"',
            '          end_date: "2099-01-05T18:00:00+02:00"',
        ]));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--format' => 'html',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('Dear Customer', $row['message']);
        self::assertStringNotContainsString('Sehr geehrter', $row['message']);
    }

    public function testSetWithWriteConfigPersistsLanguage(): void
    {
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new AutoresponderSetCommand(), $context);
        $tester->execute([
            'email' => 'user@company.com',
            '--language' => 'en',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('written to', $tester->getDisplay());

        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertSame([
            [
                'address' => 'user@company.com',
                'end_date' => '2099-01-05T08:00:00+02:00',
                'language' => 'en',
                'start_date' => '2099-01-01T08:00:00+02:00',
            ],
        ], $raw['mail']['autoresponder']);
        $this->context->configLoader()->load($this->base.'/config/plead.yaml');
    }

    public function testTemplateOverrideNotFoundFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => '/nonexistent/template.twig',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--preview' => true,
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Cannot read template file', $tester->getDisplay());
    }

    public function testSubjectAndFormatStoredAndPushed(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--subject' => 'Automatische Antwort',
            '--format' => 'html',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertSame('Automatische Antwort', $row['subject']);
        self::assertSame('html', $row['format']);
    }

    public function testInvalidFormatFails(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--format' => 'markdown',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Invalid value for --format', $tester->getDisplay());
    }

    public function testConfigEntrySubjectAndFormatApplyOnFallback(): void
    {
        file_put_contents($this->base.'/config/plead.yaml', implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'mail:',
            '    autoresponder:',
            '        - address: user@company.com',
            '          subject: "Automatische Antwort"',
            '          format: html',
            '          start_date: "2099-01-01T08:00:00+02:00"',
            '          end_date: "2099-01-05T18:00:00+02:00"',
        ]));

        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute(['email' => 'user@company.com']);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertSame('Automatische Antwort', $row['subject']);
        self::assertSame('html', $row['format']);
    }

    public function testHtmlMessageWithoutHtmlFormatFailsUnlessForced(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--format' => 'plain',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('message is HTML but the format is not "html"', $tester->getDisplay());
        self::assertNull($this->context->autoReplyRepository()->find('user@company.com'));

        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--format' => 'plain',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--force' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertStringContainsString('<!doctype html>', $row['message']);
        self::assertSame('plain', $row['format']);
    }

    public function testHtmlMessageWithoutExplicitFormatFailsUnlessForced(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertNotSame(0, $tester->getStatusCode());

        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--force' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertSame('', $row['format']);
    }

    public function testHtmlMessageWithHtmlFormatSucceeds(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--format' => 'html',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        $row = $this->context->autoReplyRepository()->find('user@company.com');
        self::assertSame('html', $row['format']);
    }

    public function testPreviewWarnsOnHtmlWithoutHtmlFormat(): void
    {
        $tester = $this->tester(new AutoresponderSetCommand());
        $tester->execute([
            'email' => 'user@company.com',
            '--template' => 'resources/templates/autoresponder.mjml.twig',
            '--end-date' => '2099-01-05T08:00:00+02:00',
            '--preview' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('message is HTML but the format is not "html"', $tester->getDisplay());
    }

    public function testSetWithWriteConfigPersistsSubjectAndFormat(): void
    {
        $context = new RuntimeContext($this->paths, null, false, null, 0, writeConfig: true, gateway: $this->gateway);

        $tester = $this->tester(new AutoresponderSetCommand(), $context);
        $tester->execute([
            'email' => 'user@company.com',
            '--subject' => 'Automatische Antwort',
            '--format' => 'plain',
            '--start-date' => '2099-01-01T08:00:00+02:00',
            '--end-date' => '2099-01-05T08:00:00+02:00',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('written to', $tester->getDisplay());

        $raw = ConfigFile::read($this->base.'/config/plead.yaml');
        self::assertSame([
            [
                'address' => 'user@company.com',
                'end_date' => '2099-01-05T08:00:00+02:00',
                'format' => 'plain',
                'start_date' => '2099-01-01T08:00:00+02:00',
                'subject' => 'Automatische Antwort',
            ],
        ], $raw['mail']['autoresponder']);
        $this->context->configLoader()->load($this->base.'/config/plead.yaml');
    }

    private function tester(AbstractPleadCommand $command, ?RuntimeContext $context = null): CommandTester
    {
        $tester = new CommandTester($command);
        $command->setContext($context ?? $this->context);

        return $tester;
    }

    private function configWithAutoresponder(?string $dataFile = null, ?bool $inclusive = null): void
    {
        $lines = [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'mail:',
            '    autoresponder:',
            '        - address: user@company.com',
        ];
        if (null !== $dataFile) {
            $lines[] = '          data_file: '.$dataFile;
        }
        if (null !== $inclusive) {
            $lines[] = '          inclusive: '.($inclusive ? 'true' : 'false');
        }
        $lines[] = '          start_date: "2099-01-01T08:00:00+02:00"';
        $lines[] = '          end_date: "2099-01-05T18:00:00+02:00"';

        file_put_contents($this->base.'/config/plead.yaml', implode("\n", $lines));
    }
}
