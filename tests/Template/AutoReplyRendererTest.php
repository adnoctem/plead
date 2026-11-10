<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Template\AutoReplyRenderer;
use App\Template\MjmlConverter;
use App\Tests\Support\FakeBin;
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
    private string $defaultTimezone;

    protected function setUp(): void
    {
        // bin/plead pins the runtime timezone; Twig's date filter uses it.
        $this->defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

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
        date_default_timezone_set($this->defaultTimezone);
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('translation catalogs');

        $renderer->renderMessage(null, null, null, null);
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
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, implode("\n", [
            'email:',
            '    de: buchhaltung@example.com',
            '    en: invoices@example.com',
            'phonenumber: "+49 123 456789"',
        ]));

        $out = $renderer->renderMessage($dataFile, null, null);

        self::assertSame('buchhaltung@example.com|+49 123 456789;invoices@example.com|+49 123 456789;', $out);
    }

    public function testFlatDataFallsBackForEveryLanguage(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['greeting' => 'Hello']);
        $templateFile = $this->base.'/flat.txt.twig';
        file_put_contents($templateFile, '{% for lang in languages %}{{ email[lang] ?? email }};{% endfor %}');
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, 'email: "office@example.com"'."\n");

        $out = $renderer->renderMessage($dataFile, null, null);

        self::assertSame('office@example.com;office@example.com;', $out);
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

        self::assertSame('https://cdn.example.com/logo.png', $renderer->renderMessage(null, null, null, null));
    }

    public function testDataFileLogoOverridesConstructor(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/logo.txt.twig';
        file_put_contents($templateFile, "{{ logo_url ?? 'default.png' }}");
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, 'https://cdn.example.com/logo.png');
        $dataFile = $this->base.'/data.yaml';
        file_put_contents($dataFile, 'logo_url: "https://cdn.example.com/christmas.png"'."\n");

        self::assertSame(
            'https://cdn.example.com/christmas.png',
            $renderer->renderMessage($dataFile, null, null),
        );
    }

    public function testTemplateDefaultLogoUsedWithoutConfig(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/logo.txt.twig';
        file_put_contents($templateFile, "{{ logo_url ?? 'default.png' }}");
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, null);

        self::assertSame('default.png', $renderer->renderMessage(null, null, null, null));
    }

    public function testRenderMessageInjectsDatesAndInclusive(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $templateFile = $this->base.'/dates.txt.twig';
        file_put_contents(
            $templateFile,
            '{{ startDate }}|{{ endDate }}|{% if inclusive %}yes{% else %}no{% endif %}',
        );
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);

        $out = $renderer->renderMessage(null, '2026-08-20T08:00:00+02:00', '2026-08-30T18:00:00+02:00', true);

        self::assertSame('2026-08-20T08:00:00+02:00|2026-08-30T18:00:00+02:00|yes', $out);
    }

    public function testLanguageInjectedIntoContext(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $this->writeCatalog('en', ['greeting' => 'Hello']);
        $templateFile = $this->base.'/lang.txt.twig';
        file_put_contents($templateFile, '{{ language }}');
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs);

        self::assertSame('de', $renderer->renderMessage(null, null, null, null));
        self::assertSame('en', $renderer->renderMessage(null, null, null, null, 'en'));
    }

    public function testUnknownLanguageThrows(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $renderer = new AutoReplyRenderer($this->templateFile, $this->catalogs);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown autoresponder language "fr"');

        $renderer->renderMessage(null, null, null, null, 'fr');
    }

    public function testMjmlOutputIsConvertedToHtml(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $fakeBinary = FakeBin::install($this->mjmlFixture(), $this->base.'/fake-mjml');
        $templateFile = $this->base.'/mail.mjml.twig';
        file_put_contents($templateFile, '<mjml><mj-body><mj-section><mj-column><mj-text>{{ "greeting"|trans({}, "autoresponder", language) }}</mj-text></mj-column></mj-section></mj-body></mjml>');
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, null, new MjmlConverter($fakeBinary));

        $out = $renderer->renderMessage(null, null, null, null, 'de');

        self::assertStringContainsString('<!doctype html>', $out);
        self::assertStringContainsString('<mjml><mj-body>', $out);
        self::assertStringContainsString('Hallo', $out);
    }

    public function testNonMjmlOutputIsLeftUntouched(): void
    {
        $this->writeCatalog('de', ['greeting' => 'Hallo']);
        $fakeBinary = FakeBin::install($this->mjmlFixture(), $this->base.'/fake-mjml');
        $templateFile = $this->base.'/plain.txt.twig';
        file_put_contents($templateFile, 'Nur Text');
        $renderer = new AutoReplyRenderer($templateFile, $this->catalogs, null, new MjmlConverter($fakeBinary));

        self::assertSame('Nur Text', $renderer->renderMessage(null, null, null, null));
    }

    public function testSingleTemplateUsesTheForwardingSentenceWithADeputy(): void
    {
        $this->writeCatalog('de', [
            'greeting' => 'Hallo',
            'date_format' => 'd.m.Y',
            'period_inclusive' => 'vom %start% bis einschließlich %end%',
            'absence' => 'nicht weitergeleitet',
            'forwarded_to' => 'weitergeleitet an %deputy%',
            'regards' => 'Gruß',
        ]);
        $template = dirname(__DIR__, 2).'/resources/templates/autoresponder.txt.twig';
        $renderer = new AutoReplyRenderer($template, $this->catalogs);

        $out = $renderer->renderMessage(
            null,
            '2026-09-17T00:00:00+02:00',
            '2026-09-18T23:59:59+02:00',
            true,
            'de',
            ['deputyEmail' => 'deputy@company.com', 'firstname' => 'Erika', 'surname' => 'Muster'],
        );

        self::assertStringContainsString('weitergeleitet an deputy@company.com', $out);
        self::assertStringNotContainsString('nicht weitergeleitet', $out);
        self::assertStringContainsString('Erika Muster', $out);
        self::assertStringContainsString('17.09.2026', $out);
    }

    public function testSingleTemplateUsesTheAbsenceSentenceWithoutADeputy(): void
    {
        $this->writeCatalog('de', [
            'greeting' => 'Hallo',
            'date_format' => 'd.m.Y',
            'period_inclusive' => 'vom %start% bis einschließlich %end%',
            'absence' => 'nicht weitergeleitet',
            'forwarded_to' => 'weitergeleitet an %deputy%',
            'regards' => 'Gruß',
        ]);
        $template = dirname(__DIR__, 2).'/resources/templates/autoresponder.txt.twig';
        $renderer = new AutoReplyRenderer($template, $this->catalogs);

        $out = $renderer->renderMessage(
            null,
            '2026-09-17T00:00:00+02:00',
            '2026-09-18T23:59:59+02:00',
            true,
            'de',
            ['firstname' => 'Erika', 'surname' => 'Muster'],
        );

        self::assertStringContainsString('nicht weitergeleitet', $out);
        self::assertStringNotContainsString('weitergeleitet an', $out);
    }

    public function testScheduleValuesWinOverInlineData(): void
    {
        $this->writeCatalog('de', [
            'greeting' => '%start%',
            'date_format' => 'd.m.Y',
            'period_inclusive' => 'x',
            'absence' => 'y',
            'forwarded_to' => 'z',
            'regards' => 'y',
        ]);
        $template = dirname(__DIR__, 2).'/resources/templates/autoresponder.txt.twig';
        $renderer = new AutoReplyRenderer($template, $this->catalogs);

        $out = $renderer->renderMessage(
            null,
            '2026-09-17T00:00:00+02:00',
            '2026-09-18T23:59:59+02:00',
            true,
            'de',
            ['startDate' => 'DATA-FILE-WINS'],
        );

        self::assertStringContainsString('17.09.2026', $out);
        self::assertStringNotContainsString('DATA-FILE-WINS', $out);
    }

    private function mjmlFixture(): string
    {
        return dirname(__DIR__).'/Support/fake-bin/mjml.php';
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
