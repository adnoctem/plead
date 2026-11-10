<?php

declare(strict_types=1);

namespace App\Template\DevServer;

use App\Config\ConfigLoader;
use App\Config\PathProvider\PathProviderFactory;
use App\Template\TemplateRenderRequest;
use App\Template\TemplateStudio;
use App\Util\DateNormalizer;

/**
 * Request handler for the template development server. It is intentionally
 * framework-free: php -S passes every request here, and the handler renders
 * through TemplateStudio. No Plesk connection or credentials are used.
 */
final class Server
{
    /** @param array<string, mixed> $state */
    public function __construct(private readonly array $state) {}

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     */
    public function handle(array $server, array $get): void
    {
        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

        if ('/' === $path || '/index.html' === $path) {
            $this->serveFile($this->projectRoot().'/resources/dev-server/index.html', 'text/html; charset=utf-8');

            return;
        }

        if ('/assets/app.css' === $path) {
            $this->serveFile($this->projectRoot().'/resources/dev-server/app.css', 'text/css; charset=utf-8');

            return;
        }

        if ('/assets/app.js' === $path) {
            $this->serveFile($this->projectRoot().'/resources/dev-server/app.js', 'text/javascript; charset=utf-8');

            return;
        }

        if ('/api/state' === $path) {
            $this->handleState($get);

            return;
        }

        if ('/api/render' === $path) {
            $this->handleRender($get);

            return;
        }

        if ('/favicon.ico' === $path) {
            http_response_code(204);

            return;
        }

        $this->json(['ok' => false, 'error' => 'Not found'], 404);
    }

    /** @param array<string, mixed> $get */
    private function handleState(array $get): void
    {
        try {
            $studio = $this->studio();
            $request = $this->request($get, $studio);
            $languages = $studio->languagesFor($request);
            $error = null;
        } catch (\Throwable $e) {
            $studio = $this->studioOrDefault();
            $languages = [];
            $error = $e->getMessage();
            $request = $this->fallbackRequest($studio);
        }

        $templates = $studio->templates();
        if (isset($this->state['template'])) {
            $templates[] = $studio->resolveTemplatePath((string) $this->state['template']);
        }
        $dataFiles = $studio->dataFiles();
        if (isset($this->state['data_file'])) {
            $dataFiles[] = $studio->resolveDataFile((string) $this->state['data_file']) ?? (string) $this->state['data_file'];
        }

        $this->json([
            'ok' => true,
            'state' => $studio->stateHash($request),
            'watch' => (bool) ($this->state['watch'] ?? true),
            'templates' => $this->listPaths(array_values(array_unique($templates))),
            'dataFiles' => $this->listPaths(array_values(array_unique($dataFiles))),
            'languages' => $languages,
            'current' => [
                'template' => $request->templatePath,
                'data' => $request->dataFile,
                'language' => $request->language,
                'deputy' => $request->deputyEmail,
                'start' => $request->startDate,
                'end' => $request->endDate,
                'inclusive' => $request->inclusive,
                'logo' => $request->logoUrl,
            ],
            'error' => $error,
        ]);
    }

    /** @param array<string, mixed> $get */
    private function handleRender(array $get): void
    {
        try {
            $studio = $this->studio();
            $request = $this->request($get, $studio);
            $rendered = $studio->render($request);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()]);

            return;
        }

        $this->json([
            'ok' => true,
            'state' => $studio->stateHash($request),
            'source' => $rendered->source,
            'html' => $rendered->html,
            'kind' => $rendered->kind,
            'languages' => $rendered->languages,
            'template' => $rendered->templatePath,
        ]);
    }

    /**
     * @param array<string, mixed> $get
     *
     * @throws \InvalidArgumentException
     */
    private function request(array $get, TemplateStudio $studio): TemplateRenderRequest
    {
        $inbox = (bool) ($this->state['inbox'] ?? false);
        $allowedTemplates = $this->allowed($studio->templates(), $studio->resolveTemplatePath((string) ($this->state['template'] ?? $studio->defaultTemplate($inbox))));
        $template = $this->string($get, 'template', (string) ($this->state['template'] ?? $studio->defaultTemplate($inbox)));
        $template = $studio->resolveTemplatePath($template);
        if (!in_array($template, $allowedTemplates, true)) {
            throw new \InvalidArgumentException('Unknown template: '.$template);
        }

        $allowedDataFiles = $this->allowed($studio->dataFiles(), null === ($this->state['data_file'] ?? null) ? null : $studio->resolveDataFile((string) $this->state['data_file']));
        $data = $this->string($get, 'data', (string) ($this->state['data_file'] ?? ''));
        $dataFile = '' === $data ? null : $studio->resolveDataFile($data);
        if (null !== $dataFile && !in_array($dataFile, $allowedDataFiles, true)) {
            throw new \InvalidArgumentException('Unknown data file: '.$dataFile);
        }

        $translations = (string) ($this->state['translations'] ?? $studio->defaultTranslations($inbox));
        $language = $this->string($get, 'language', (string) ($this->state['language'] ?? $studio->defaultLanguage($inbox) ?? ''));

        $deputy = $this->string($get, 'deputy', (string) ($this->state['deputy_email'] ?? ''));
        if ('' !== $deputy && false === filter_var($deputy, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid deputy email: %s', $deputy));
        }

        $start = $this->string($get, 'start', (string) ($this->state['start_date'] ?? ''));
        $end = $this->string($get, 'end', (string) ($this->state['end_date'] ?? ''));
        $inclusive = $this->boolean($get, 'inclusive', (bool) ($this->state['inclusive'] ?? true));

        $logo = $this->string($get, 'logo', (string) ($this->state['logo_url'] ?? ''));

        return new TemplateRenderRequest(
            $template,
            $dataFile,
            $translations,
            '' === $logo ? null : $logo,
            '' === $language ? null : $language,
            '' === $deputy ? null : $deputy,
            DateNormalizer::normalize('' === $start ? 'today 00:00' : $start),
            DateNormalizer::normalize('' === $end ? '+7 days' : $end),
            $inclusive,
        );
    }

    private function fallbackRequest(TemplateStudio $studio): TemplateRenderRequest
    {
        return new TemplateRenderRequest(
            $studio->resolveTemplatePath((string) ($this->state['template'] ?? $studio->defaultTemplate((bool) ($this->state['inbox'] ?? false)))),
            null === ($this->state['data_file'] ?? null) ? null : $studio->resolveDataFile((string) $this->state['data_file']),
            (string) ($this->state['translations'] ?? $studio->defaultTranslations((bool) ($this->state['inbox'] ?? false))),
            null === ($this->state['logo_url'] ?? null) ? null : (string) $this->state['logo_url'],
            null === ($this->state['language'] ?? null) ? null : (string) $this->state['language'],
            null === ($this->state['deputy_email'] ?? null) ? null : (string) $this->state['deputy_email'],
            DateNormalizer::normalize((string) ($this->state['start_date'] ?? 'today 00:00')),
            DateNormalizer::normalize((string) ($this->state['end_date'] ?? '+7 days')),
            (bool) ($this->state['inclusive'] ?? true),
        );
    }

    private function studio(): TemplateStudio
    {
        $provider = PathProviderFactory::create();
        $configFile = $this->state['config_file'] ?? null;
        $config = new ConfigLoader($provider)->load(is_string($configFile) && '' !== $configFile ? $configFile : null);
        $configFiles = $provider->configPaths();
        if (is_string($configFile) && '' !== $configFile) {
            $configFiles[] = $configFile;
        }

        return new TemplateStudio($config, array_values(array_unique($configFiles)));
    }

    private function studioOrDefault(): TemplateStudio
    {
        try {
            return $this->studio();
        } catch (\Throwable) {
            $provider = PathProviderFactory::create();

            return new TemplateStudio([], $provider->configPaths());
        }
    }

    /**
     * @param string[] $paths
     *
     * @return array<int, array{path: string, label: string}>
     */
    private function listPaths(array $paths): array
    {
        return array_map(fn (string $path): array => [
            'path' => $path,
            'label' => $this->label($path),
        ], $paths);
    }

    /**
     * @param string[] $allowed
     *
     * @return list<string>
     */
    private function allowed(array $allowed, ?string $extra): array
    {
        if (null !== $extra && '' !== $extra) {
            $allowed[] = $extra;
        }

        return array_values(array_unique($allowed));
    }

    /** @param array<string, mixed> $get */
    private function string(array $get, string $key, string $default): string
    {
        $value = $get[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @param array<string, mixed> $get */
    private function boolean(array $get, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $get)) {
            return $default;
        }

        $value = filter_var($get[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return null === $value ? $default : $value;
    }

    private function label(string $path): string
    {
        $root = $this->projectRoot().'/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return $path;
    }

    private function serveFile(string $path, string $contentType): void
    {
        if (!is_file($path)) {
            $this->json(['ok' => false, 'error' => 'Asset not found'], 404);

            return;
        }

        header('Content-Type: '.$contentType);
        header('Cache-Control: no-store');
        readfile($path);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
