<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Template\AutoReplyRenderer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class AutoReplyRendererTest extends TestCase
{
    private string $base;
    private string $templateFile;
    private string $catalogs;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/plead-renderer-'.bin2hex(random_bytes(4));
        $this->catalogs = $this->base.'/translations';
        mkdir($this->catalogs, 0o777, true);
        $this->templateFile = $this->base.'/message.txt.twig';
        file_put_contents(
            $this->templateFile,
            "{% for lang in languages %}[{{ lang }}] {{ 'greeting'|trans({}, 'autoresponder', lang) }}{% endfor %}",
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($this->base);
    }

    public function testLanguagesDetectedInGlobOrder(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['greeting' => 'Hello']);
        $renderer = new AutoReplyRenderer($this->templateFile, $this->catalogs);

        self::assertSame(['de', 'en'], $renderer->languages());
        self::assertSame('[de] Hallo[en] Hello', $renderer->render(['languages' => $renderer->languages()]));
    }

    public function testAdditionalCatalogAddsLanguage(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['greeting' => 'Hello']);
        $this->writeCatalog('fr', ['greeting' => 'Bonjour']);
        $renderer = new AutoReplyRenderer($this->templateFile, $this->catalogs);

        self::assertSame(['de', 'en', 'fr'], $renderer->languages());
        self::assertSame('[de] Hallo[en] Hello[fr] Bonjour', $renderer->render(['languages' => $renderer->languages()]));
    }

    public function testMissingKeyFallsBackToGermanNeverRawKey(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['other' => 'x']);
        $renderer = new AutoReplyRenderer($this->templateFile, $this->catalogs);

        self::assertSame('[de] Hallo[en] Hallo', $renderer->render(['languages' => $renderer->languages()]));
    }

    public function testRenderMessageWithoutCatalogsThrows(): void
    {
        $renderer = new AutoReplyRenderer($this->templateFile, null);
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('translation catalogs');

        $renderer->renderMessage($messageFile, null, null, null);
    }

    public function testKeyLangResolvesLocaleMaps(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['greeting' => 'Hello']);
        $templateFile = $this->base.'/maps.txt.twig';
        file_put_contents(
            $templateFile,
            '{% for lang in languages %}{{ email[lang] ?? email }}|{{ phonenumber[lang] ?? phonenumber }};{% endfor %}',
        );
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'x');
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", [
            'email:',
            '    de: buchhaltung@delta4x4.com',
            '    en: invoices@delta4x4.com',
            'phonenumber: "+49 8134 930217"',
        ]));

        $out = $renderer->renderMessage($messageFile, $dataFile, null, null);

        self::assertSame('buchhaltung@delta4x4.com|+49 8134 930217;invoices@delta4x4.com|+49 8134 930217;', $out);
    }

    public function testFlatDataFallsBackForEveryLanguage(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['greeting' => 'Hello']);
        $templateFile = $this->base.'/flat.txt.twig';
        file_put_contents($templateFile, '{% for lang in languages %}{{ email[lang] ?? email }};{% endfor %}');
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'x');
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, 'email: "office@delta4x4.com"'."\n");

        $out = $renderer->renderMessage($messageFile, $dataFile, null, null);

        self::assertSame('office@delta4x4.com;office@delta4x4.com;', $out);
    }

    public function testDateFormatResolvedPerLocale(): void
    {
        $this->writeCatalog('de', ['date_format' => 'd.m.Y']);
        $this->writeCatalog('en', ['date_format' => 'F j, Y']);
        $templateFile = $this->base.'/dates.txt.twig';
        file_put_contents(
            $templateFile,
            "{% for lang in languages %}{{ 'date_format'|trans({}, 'autoresponder', lang) }};{% endfor %}",
        );
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);

        self::assertSame('d.m.Y;F j, Y;', $renderer->render(['languages' => $renderer->languages()]));
    }

    public function testLogoFromConstructorInjectedAsFallback(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/logo.txt.twig';
        file_put_contents($templateFile, "{{ logo_url ?? 'default.png' }}");
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, 'https://cdn.example.com/logo.png');
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'x');

        self::assertSame('https://cdn.example.com/logo.png', $renderer->renderMessage($messageFile, null, null, null));
    }

    public function testDataFileLogoOverridesConstructor(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/logo.txt.twig';
        file_put_contents($templateFile, "{{ logo_url ?? 'default.png' }}");
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, 'https://cdn.example.com/logo.png');
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'x');
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, 'logo_url: "https://cdn.example.com/christmas.png"'."\n");

        self::assertSame(
            'https://cdn.example.com/christmas.png',
            $renderer->renderMessage($messageFile, $dataFile, null, null),
        );
    }

    public function testTemplateDefaultLogoUsedWithoutConfig(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/logo.txt.twig';
        file_put_contents($templateFile, "{{ logo_url ?? 'default.png' }}");
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, null);
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'x');

        self::assertSame('default.png', $renderer->renderMessage($messageFile, null, null, null));
    }

    public function testRenderMessageInjectsScheduleAndMessageFile(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/passthrough.txt.twig';
        file_put_contents($templateFile, '{{ message }}');
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);
        $messageFile = $this->base.'/body.txt';
        file_put_contents($messageFile, 'Gruß {{ firstname }}');
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, "firstname: Max\n");

        $out = $renderer->renderMessage($messageFile, $dataFile, '2026-08-20T08:00:00+02:00', '2026-08-30T18:00:00+02:00');

        self::assertSame('Gruß Max', $out);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    /** @param array<string, string> $messages */
    private function writeCatalog(string $locale, array $messages): void
    {
        $lines = [];
        foreach ($messages as $key => $value) {
            $lines[] = $key.': "'.str_replace('"', '\"', $value).'"';
        }
        file_put_contents($this->catalogs.'/autoresponder.'.$locale.'.yaml', implode("\n", $lines)."\n");
    }
}
