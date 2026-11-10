<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Template\MjmlConverter;
use App\Template\RenderedTemplate;
use App\Template\TemplateRenderRequest;
use App\Template\TemplateStudio;
use App\Tests\Support\FakeBin;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class TemplateStudioTest extends TestCase
{
    private string $base;
    private string $textTemplate;
    private string $mjmlTemplate;
    private string $translations;
    private string $dataFile;
    private TemplateStudio $studio;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-template-studio-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/templates', 0o777, true);
        mkdir($this->base.'/translations', 0o777, true);

        $this->textTemplate = $this->base.'/templates/message.txt.twig';
        file_put_contents(
            $this->textTemplate,
            "{{ 'greeting'|trans({}, 'autoresponder', language) }}|{{ 'absence'|trans({}, 'autoresponder', language) }}|{{ 'forwarded_to'|trans({}, 'autoresponder', language) }}|{{ deputyEmail ?? '' }}",
        );

        $this->mjmlTemplate = $this->base.'/templates/message.mjml.twig';
        file_put_contents(
            $this->mjmlTemplate,
            '<mjml><mj-body><mj-section><mj-column><mj-text>{{ \'greeting\'|trans({}, "autoresponder", language) }}</mj-text></mj-column></mj-section></mj-body></mjml>',
        );

        $this->translations = $this->base.'/translations';
        file_put_contents($this->translations.'/autoresponder.de.yaml', implode("\n", [
            'greeting: "Hallo"',
            'absence: "abwesend"',
            'forwarded_to: "an %deputy% weitergeleitet"',
        ]));

        $this->dataFile = $this->base.'/data.yaml';
        file_put_contents($this->dataFile, 'firstname: Erika');

        $fakeMjml = FakeBin::install(dirname(__DIR__).'/Support/fake-bin/mjml.php', $this->base.'/fake-mjml');

        $this->studio = new TemplateStudio([
            'template' => [
                'paths' => [
                    'auto_reply' => $this->textTemplate,
                    'translations' => $this->translations,
                ],
                'logo_url' => null,
                'language' => 'de',
            ],
            'watch' => [
                'inbox' => [
                    'template' => $this->mjmlTemplate,
                    'translations' => $this->translations,
                    'data_file' => $this->dataFile,
                    'inclusive' => true,
                ],
            ],
        ], [], new MjmlConverter($fakeMjml));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->base);
    }

    public function testDefaultsFollowTheConfig(): void
    {
        self::assertSame($this->textTemplate, $this->studio->defaultTemplate());
        self::assertSame($this->mjmlTemplate, $this->studio->defaultTemplate(true));
        self::assertSame($this->translations, $this->studio->defaultTranslations());
        self::assertSame($this->dataFile, $this->studio->defaultDataFile(true));
        self::assertTrue($this->studio->defaultInclusive(true));
        self::assertSame('de', $this->studio->defaultLanguage());
    }

    public function testRendersTextSourceAndHtml(): void
    {
        $result = $this->studio->render($this->request($this->textTemplate));

        self::assertSame(RenderedTemplate::KIND_TEXT, $result->kind);
        self::assertStringContainsString('Hallo', $result->source);
        self::assertStringContainsString('abwesend', $result->source);
        self::assertSame($result->source, $result->html);
    }

    public function testRendersMjmlSourceAndConvertedHtml(): void
    {
        $result = $this->studio->render($this->request($this->mjmlTemplate));

        self::assertSame(RenderedTemplate::KIND_MJML, $result->kind);
        self::assertStringStartsWith('<mjml', ltrim($result->source));
        self::assertStringContainsString('<!doctype html>', $result->html);
        self::assertStringContainsString('Hallo', $result->html);
    }

    public function testDeputyEmailReachesTheTemplate(): void
    {
        $request = $this->request($this->textTemplate, 'deputy@example.com');
        $result = $this->studio->render($request);

        self::assertStringContainsString('deputy@example.com', $result->source);
    }

    public function testStateHashChangesWhenTheTemplateChanges(): void
    {
        $request = $this->request($this->textTemplate);
        $before = $this->studio->stateHash($request);

        file_put_contents($this->textTemplate, 'changed');
        clearstatcache(true, $this->textTemplate);

        self::assertNotSame($before, $this->studio->stateHash($request));
    }

    public function testTemplatesAndDataFilesAreDiscovered(): void
    {
        self::assertContains($this->textTemplate, $this->studio->templates());
        self::assertContains($this->mjmlTemplate, $this->studio->templates());
        self::assertContains($this->dataFile, $this->studio->dataFiles());
    }

    public function testMissingTemplateFailsClearly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template not found');

        $this->studio->render($this->request($this->base.'/missing.twig'));
    }

    private function request(string $template, ?string $deputy = null): TemplateRenderRequest
    {
        return new TemplateRenderRequest(
            $template,
            $this->dataFile,
            $this->translations,
            null,
            'de',
            $deputy,
            '2026-11-10T00:00:00+01:00',
            '2026-11-17T23:59:59+01:00',
            true,
        );
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
