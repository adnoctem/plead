<?php

declare(strict_types=1);

namespace App\Template;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders autoresponder templates. The sentences live in
 * translations/autoresponder.{locale}.yaml catalogs (the template is pure
 * structure and iterates the available languages); 'de' is the fallback
 * locale, so a missing entry never leaks a raw key and never fails a render.
 */
final class AutoReplyRenderer
{
    private readonly Environment $twig;

    /** @var list<string> locales with a catalog, in glob (alphabetical) order */
    private readonly array $languages;

    /**
     * @param null|string $translationsPath directory with autoresponder.{locale}.yaml
     *                                      catalogs; null or missing = no languages
     * @param null|string $logoUrl          per-install logo (template.logo_url); a
     *                                      data-file logo_url may override it
     */
    public function __construct(
        private readonly string $templatePath,
        ?string $translationsPath = null,
        private readonly ?string $logoUrl = null,
    ) {
        $this->twig = new Environment(
            new FilesystemLoader(dirname($templatePath)),
            ['autoescape' => false],
        );

        // The extension is always registered: {% trans %} blocks and the
        // |trans filter must resolve even without any catalog.
        $this->twig->addExtension(new TranslationExtension($this->buildTranslator($translationsPath)));

        $this->languages = $this->discoverLanguages($translationsPath);
    }

    /** @return list<string> the locales with a translation catalog */
    public function languages(): array
    {
        return $this->languages;
    }

    /** @param array<string, mixed> $context */
    public function render(array $context): string
    {
        return trim($this->twig->render(basename($this->templatePath), $context));
    }

    /**
     * Render an auto-reply message from its message file, optionally enriched
     * with template data from a JSON/YAML data file. The message file is
     * rendered as an inline Twig template with the same context (so its
     * {{ firstname }}-style placeholders resolve too), then handed to the
     * configured template as the 'message' variable. The scheduling dates,
     * the inclusive flag and the language list are injected automatically
     * (CLI/configuration is authoritative over the data file); per-language
     * data values use locale maps resolved as key[lang] ?? key. The logo URL
     * is config-backed but a data-file value may override it.
     */
    public function renderMessage(
        string $messageFile,
        ?string $dataFile,
        ?string $startDate,
        ?string $endDate,
        ?bool $inclusive = null,
    ): string {
        if (!is_file($messageFile) || !is_readable($messageFile)) {
            throw new \RuntimeException(sprintf('Cannot read autoresponder message file: %s', $messageFile));
        }

        $context = [
            'date' => new \DateTimeImmutable(),
            'languages' => $this->languages,
        ];
        if (null !== $startDate) {
            $context['startDate'] = $startDate;
        }
        if (null !== $endDate) {
            $context['endDate'] = $endDate;
        }
        if (null !== $inclusive) {
            $context['inclusive'] = $inclusive;
        }

        if (null !== $dataFile) {
            $context = array_merge($this->loadDataFile($dataFile), $context);
        }

        // The logo is per installation (template.logo_url); the data file may
        // override it per campaign (e.g. a seasonal logo).
        if (null !== $this->logoUrl && !isset($context['logo_url'])) {
            $context['logo_url'] = $this->logoUrl;
        }

        $context['message'] = $this->twig->createTemplate((string) file_get_contents($messageFile))->render($context);

        $message = $this->render($context);

        // The shipped templates render their sections from the catalogs; an
        // empty render without any catalog would silently store an empty
        // auto-reply, so surface it loudly instead. Custom templates that
        // render content on their own (e.g. {{ message }}) are unaffected.
        if ('' === $message && [] === $this->languages) {
            throw new \RuntimeException(
                'No autoresponder translation catalogs found - the template sentences come from '
                .'translations/autoresponder.{locale}.yaml (see template.translations_path).',
            );
        }

        return $message;
    }

    /**
     * Build the catalog-backed translator for the 'autoresponder' domain.
     * Every autoresponder.{locale}.yaml in the translations path is loaded for
     * its locale; 'de' is the fallback locale, so a missing entry degrades to
     * the German text - never a raw key, never a failed render.
     */
    private function buildTranslator(?string $translationsPath): Translator
    {
        $translator = new Translator('de');
        $translator->addLoader('yaml', new YamlFileLoader());

        if (null !== $translationsPath && is_dir($translationsPath)) {
            foreach ($this->discoverLanguages($translationsPath) as $locale) {
                $translator->addResource('yaml', $translationsPath.'/autoresponder.'.$locale.'.yaml', $locale, 'autoresponder');
            }
        }
        $translator->setFallbackLocales(['de']);

        return $translator;
    }

    /** @return list<string> locales with a catalog file, in glob order */
    private function discoverLanguages(?string $translationsPath): array
    {
        if (null === $translationsPath || !is_dir($translationsPath)) {
            return [];
        }

        // scandir (not glob): glob() returns nothing for phar:// paths.
        $languages = [];
        foreach (scandir($translationsPath) ?: [] as $entry) {
            if (preg_match('/^autoresponder\.([^.]+)\.yaml$/', $entry, $matches)) {
                $languages[] = $matches[1];
            }
        }
        sort($languages);

        return $languages;
    }

    /**
     * Load template data from a JSON or YAML file (extension-based with a
     * content fallback) into a flat variable map.
     *
     * @return array<string, mixed>
     */
    private function loadDataFile(string $dataFile): array
    {
        if (!is_file($dataFile) || !is_readable($dataFile)) {
            throw new \RuntimeException(sprintf('Cannot read autoresponder data file: %s', $dataFile));
        }

        $contents = (string) file_get_contents($dataFile);
        $extension = strtolower(pathinfo($dataFile, PATHINFO_EXTENSION));

        if ('json' === $extension) {
            return $this->parseJson($contents, $dataFile);
        }
        if ('yaml' === $extension || 'yml' === $extension) {
            return $this->parseYaml($contents, $dataFile);
        }

        try {
            return $this->parseJson($contents, $dataFile);
        } catch (\Throwable) {
            return $this->parseYaml($contents, $dataFile);
        }
    }

    /** @return array<string, mixed> */
    private function parseJson(string $contents, string $dataFile): array
    {
        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Data file "%s" is not valid JSON: %s', $dataFile, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Data file "%s" must contain a JSON object.', $dataFile));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function parseYaml(string $contents, string $dataFile): array
    {
        try {
            $data = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw new \RuntimeException(sprintf('Data file "%s" is not valid YAML: %s', $dataFile, $e->getMessage()), 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('Data file "%s" must contain a YAML mapping.', $dataFile));
        }

        return $data;
    }
}
