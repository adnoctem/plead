<?php

declare(strict_types=1);

namespace App\Template;

/**
 * Template discovery and rendering for the `template:*` commands and the
 * local development server. It is deliberately independent of Plesk: only
 * template/translation/data paths and the resolved application config are
 * needed.
 */
final class TemplateStudio
{
    private const DEFAULT_TEMPLATE = 'resources/templates/autoresponder.txt.twig';
    private const DEFAULT_TRANSLATIONS = 'resources/translations';

    /**
     * @param array<string, mixed> $config      merged application config
     * @param string[]             $configFiles candidate config paths (for hot reload)
     */
    public function __construct(
        private readonly array $config = [],
        private readonly array $configFiles = [],
        private readonly ?MjmlConverter $mjmlConverter = null,
    ) {}

    public function defaultTemplate(bool $inbox = false): string
    {
        if ($inbox) {
            $configured = trim((string) ($this->config['watch']['inbox']['template'] ?? ''));
            if ('' !== $configured) {
                return $configured;
            }
        }

        return trim((string) ($this->config['template']['paths']['auto_reply'] ?? self::DEFAULT_TEMPLATE));
    }

    public function defaultTranslations(bool $inbox = false): string
    {
        if ($inbox) {
            $configured = trim((string) ($this->config['watch']['inbox']['translations'] ?? ''));
            if ('' !== $configured) {
                return $configured;
            }
        }

        return trim((string) ($this->config['template']['paths']['translations'] ?? self::DEFAULT_TRANSLATIONS));
    }

    public function defaultDataFile(bool $inbox = false): ?string
    {
        if (!$inbox) {
            return null;
        }

        $configured = trim((string) ($this->config['watch']['inbox']['data_file'] ?? ''));

        return '' === $configured ? null : $configured;
    }

    public function defaultLanguage(bool $inbox = false): ?string
    {
        $configured = $inbox
            ? ($this->config['watch']['inbox']['language'] ?? null)
            : ($this->config['template']['language'] ?? null);

        $value = trim((string) ($configured ?? ''));

        return '' === $value ? null : $value;
    }

    public function defaultInclusive(bool $inbox = false): bool
    {
        if ($inbox && array_key_exists('inclusive', $this->config['watch']['inbox'] ?? [])) {
            return (bool) $this->config['watch']['inbox']['inclusive'];
        }

        return true;
    }

    public function defaultLogoUrl(): ?string
    {
        $configured = trim((string) ($this->config['template']['logo_url'] ?? ''));

        return '' === $configured ? null : $configured;
    }

    public function resolveTemplatePath(string $path): string
    {
        if (is_file($path)) {
            return realpath($path) ?: $path;
        }

        $absolute = $this->projectRoot().'/'.$path;

        return is_file($absolute) ? (realpath($absolute) ?: $absolute) : $path;
    }

    public function resolveTranslationsPath(?string $path): ?string
    {
        $configured = null === $path || '' === trim($path)
            ? $this->defaultTranslations()
            : trim($path);

        if (is_dir($configured)) {
            return realpath($configured) ?: $configured;
        }

        $absolute = $this->projectRoot().'/'.$configured;

        return is_dir($absolute) ? (realpath($absolute) ?: $absolute) : null;
    }

    public function resolveDataFile(?string $path): ?string
    {
        if (null === $path || '' === trim($path)) {
            return null;
        }

        $path = trim($path);
        if (is_file($path)) {
            return realpath($path) ?: $path;
        }

        $absolute = $this->projectRoot().'/'.$path;

        return is_file($absolute) ? (realpath($absolute) ?: $absolute) : $path;
    }

    /**
     * @return list<string> absolute template paths
     */
    public function templates(): array
    {
        $templates = $this->discover($this->projectRoot().'/resources/templates', ['twig']);
        $templates[] = $this->resolveTemplatePath($this->defaultTemplate(true));
        $templates[] = $this->resolveTemplatePath($this->defaultTemplate(false));

        return $this->uniqueExisting($templates);
    }

    /**
     * @return list<string> absolute data-file paths
     */
    public function dataFiles(): array
    {
        $files = $this->discover($this->projectRoot().'/resources/templates/data', ['yaml', 'yml', 'json']);
        $inbox = $this->defaultDataFile(true);
        if (null !== $inbox) {
            $files[] = $this->resolveDataFile($inbox) ?? $inbox;
        }

        return $this->uniqueExisting($files);
    }

    /** @return list<string> */
    public function languagesFor(TemplateRenderRequest $request): array
    {
        $templatePath = $this->resolveTemplatePath($request->templatePath);
        $renderer = new AutoReplyRenderer(
            $templatePath,
            $this->resolveTranslationsPath($request->translationsPath),
            $request->logoUrl ?? $this->defaultLogoUrl(),
            $this->mjmlConverter,
        );

        return $renderer->languages();
    }

    public function render(TemplateRenderRequest $request): RenderedTemplate
    {
        $templatePath = $this->resolveTemplatePath($request->templatePath);
        if (!is_file($templatePath)) {
            throw new \RuntimeException(sprintf('Template not found: %s', $templatePath));
        }

        $dataFile = $this->resolveDataFile($request->dataFile);
        if (null !== $dataFile && !is_file($dataFile)) {
            throw new \RuntimeException(sprintf('Data file not found: %s', $dataFile));
        }

        $translationsPath = $this->resolveTranslationsPath($request->translationsPath);
        $renderer = new AutoReplyRenderer(
            $templatePath,
            $translationsPath,
            $request->logoUrl ?? $this->defaultLogoUrl(),
            $this->mjmlConverter,
        );

        $extraData = $request->extraData;
        $extraData['deputyEmail'] = $request->deputyEmail ?? '';

        $source = $renderer->renderMessageSource(
            $dataFile,
            $request->startDate,
            $request->endDate,
            $request->inclusive,
            $request->language,
            $extraData,
        );

        $kind = str_starts_with(ltrim($source), '<mjml') ? RenderedTemplate::KIND_MJML : RenderedTemplate::KIND_TEXT;
        $html = RenderedTemplate::KIND_MJML === $kind ? $renderer->convert($source) : $source;

        return new RenderedTemplate(
            $source,
            $html,
            $kind,
            $templatePath,
            $renderer->languages(),
        );
    }

    /**
     * A stable hash of the files that influence a render. The browser polls
     * this instead of holding an SSE connection open through php -S.
     */
    public function stateHash(TemplateRenderRequest $request): string
    {
        $files = [];
        $templatePath = $this->resolveTemplatePath($request->templatePath);
        $files[] = $templatePath;
        if (null !== $request->dataFile) {
            $files[] = $this->resolveDataFile($request->dataFile) ?? $request->dataFile;
        }

        $translations = $this->resolveTranslationsPath($request->translationsPath);
        if (null !== $translations) {
            $files = array_merge($files, $this->discover($translations, ['yaml', 'yml']));
        }

        // Twig includes can live next to the selected template; watch that
        // directory recursively as well.
        $templateDirectory = dirname($templatePath);
        if (is_dir($templateDirectory)) {
            $files = array_merge($files, $this->discover($templateDirectory, ['twig']));
        }

        $files = array_merge($files, $this->configFiles);

        $state = [];
        foreach ($this->uniqueExisting($files) as $file) {
            clearstatcache(true, $file);
            $state[$file] = [filemtime($file) ?: 0, filesize($file) ?: 0];
        }

        return sha1(json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string[] $extensions
     *
     * @return list<string>
     */
    public function discover(string $directory, array $extensions): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_file($path) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $extensions, true)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param string[] $files
     *
     * @return list<string>
     */
    private function uniqueExisting(array $files): array
    {
        $unique = [];
        foreach ($files as $file) {
            if ('' === $file || !is_file($file)) {
                continue;
            }
            $unique[$file] = true;
        }

        return array_keys($unique);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
