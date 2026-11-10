<?php

declare(strict_types=1);

namespace App\Tests\Command\Template;

use App\Application\PleadApplication;
use App\Tests\Support\FakeBin;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 */
#[CoversNothing]
final class TemplateRenderCommandTest extends TestCase
{
    private string $base;
    private string $configFile;
    private string $textTemplate;
    private string $mjmlTemplate;
    private string $dataFile;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        putenv('PLEAD_SERVER');
        putenv('PLEAD_PLESK_SECRET_KEY');

        $this->base = sys_get_temp_dir().'/plead-template-render-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/templates', 0o777, true);
        mkdir($this->base.'/translations', 0o777, true);

        $this->textTemplate = $this->base.'/templates/message.txt.twig';
        file_put_contents(
            $this->textTemplate,
            "{% if deputyEmail is defined and deputyEmail %}an {{ deputyEmail }} weitergeleitet{% else %}{{ 'absence'|trans({}, 'autoresponder', language) }}{% endif %}",
        );

        $this->mjmlTemplate = $this->base.'/templates/message.mjml.twig';
        file_put_contents(
            $this->mjmlTemplate,
            '<mjml><mj-body><mj-section><mj-column><mj-text>{{ \'greeting\'|trans({}, "autoresponder", language) }}</mj-text></mj-column></mj-section></mj-body></mjml>',
        );

        file_put_contents($this->base.'/translations/autoresponder.de.yaml', implode("\n", [
            'greeting: "Hallo"',
            'absence: "nicht weitergeleitet"',
            'forwarded_to: "an %deputy% weitergeleitet"',
        ]));

        $this->dataFile = $this->base.'/data.yaml';
        file_put_contents($this->dataFile, 'firstname: Erika');

        $fakeMjml = FakeBin::install(dirname(__DIR__, 2).'/Support/fake-bin/mjml.php', $this->base.'/fake-mjml');
        putenv('MJML_BINARY_PATH='.$fakeMjml);

        $this->configFile = $this->base.'/plead.yaml';
        file_put_contents($this->configFile, implode("\n", [
            'servers:',
            '    - host: fake.local',
            '      secret_key: test-key',
            'template:',
            '    paths:',
            '        auto_reply: '.$this->textTemplate,
            '        translations: '.$this->base.'/translations',
            '    language: de',
            'watch:',
            '    inbox:',
            '        template: '.$this->mjmlTemplate,
            '        translations: '.$this->base.'/translations',
            '        data_file: '.$this->dataFile,
            '        inclusive: true',
            '',
        ]));

        $this->tester = new ApplicationTester(new PleadApplication());
    }

    protected function tearDown(): void
    {
        putenv('MJML_BINARY_PATH');
        putenv('PLEAD_SERVER');
        putenv('PLEAD_PLESK_SECRET_KEY');
        $this->removeDirectory($this->base);
    }

    public function testRendersTheConfiguredTextTemplate(): void
    {
        self::assertSame(0, $this->runCommand([]));
        self::assertStringContainsString('nicht weitergeleitet', $this->tester->getDisplay());
        self::assertStringNotContainsString('an ', $this->tester->getDisplay());
    }

    public function testDeputyEmailSelectsTheForwardingBody(): void
    {
        self::assertSame(0, $this->runCommand(['--deputy-email' => 'deputy@example.com']));
        self::assertStringContainsString('an deputy@example.com weitergeleitet', $this->tester->getDisplay());
        self::assertStringNotContainsString('nicht weitergeleitet', $this->tester->getDisplay());
    }

    public function testInboxUsesTheInboxTemplateAndData(): void
    {
        self::assertSame(0, $this->runCommand(['--inbox' => true, '--format' => 'html']));
        self::assertStringContainsString('<!doctype html>', $this->tester->getDisplay());
        self::assertStringContainsString('Hallo', $this->tester->getDisplay());
    }

    public function testOutputDirectoryWritesMjmlAndHtml(): void
    {
        $directory = $this->base.'/preview';

        self::assertSame(0, $this->runCommand([
            '--template' => $this->mjmlTemplate,
            '--output-dir' => $directory,
        ]));

        self::assertFileExists($directory.'/preview.mjml');
        self::assertFileExists($directory.'/preview.html');
        self::assertStringStartsWith('<mjml', (string) file_get_contents($directory.'/preview.mjml'));
    }

    public function testMjmlFormatRejectsATextTemplate(): void
    {
        self::assertNotSame(0, $this->runCommand(['--format' => 'mjml']));
        self::assertStringContainsString('renders text', $this->tester->getDisplay());
    }

    public function testTextFormatRejectsAnMjmlTemplate(): void
    {
        self::assertNotSame(0, $this->runCommand(['--template' => $this->mjmlTemplate, '--format' => 'text']));
        self::assertStringContainsString('renders MJML', $this->tester->getDisplay());
    }

    public function testInvalidDateFails(): void
    {
        self::assertNotSame(0, $this->runCommand(['--start-date' => 'not-a-date']));
        self::assertStringContainsString('Invalid date', $this->tester->getDisplay());
    }

    /** @param array<string, mixed> $options */
    private function runCommand(array $options): int
    {
        return $this->tester->run($options + [
            'command' => 'template:render',
            '--config' => $this->configFile,
            '--format' => 'html',
            '--language' => 'de',
            '--start-date' => '2026-11-10',
            '--end-date' => '2026-11-17',
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
