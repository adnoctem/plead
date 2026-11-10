<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Command\AbstractPleadCommand;
use App\Template\TemplateRenderRequest;
use App\Template\TemplateStudio;
use App\Util\DateNormalizer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

abstract class AbstractTemplateCommand extends AbstractPleadCommand
{
    protected function configureTemplateOptions(): void
    {
        $this
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Template path (default: template.paths.auto_reply, or watch.inbox.template with --inbox)')
            ->addOption('inbox', null, InputOption::VALUE_NONE, 'Use the inbox template, translations and data-file defaults')
            ->addOption('data-file', null, InputOption::VALUE_REQUIRED, 'JSON/YAML template data file')
            ->addOption('translations', null, InputOption::VALUE_REQUIRED, 'Translation catalog directory')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Render language (e.g. de)')
            ->addOption('deputy-email', null, InputOption::VALUE_REQUIRED, 'Render the forwarding body with this deputy address')
            ->addOption('start-date', null, InputOption::VALUE_REQUIRED, 'Absence start (YYYY-MM-DD or ISO 8601; default: today)')
            ->addOption('end-date', null, InputOption::VALUE_REQUIRED, 'Absence end (YYYY-MM-DD or ISO 8601; default: +7 days)')
            ->addOption('inclusive', null, InputOption::VALUE_NEGATABLE, 'Whether the end date is inclusive (default: config, then true)')
            ->addOption('logo-url', null, InputOption::VALUE_REQUIRED, 'Override template.logo_url for this preview')
        ;
    }

    protected function studio(): TemplateStudio
    {
        return new TemplateStudio(
            $this->context()->config(),
            $this->context()->paths->configPaths(),
        );
    }

    /** @throws \InvalidArgumentException */
    protected function request(InputInterface $input): TemplateRenderRequest
    {
        $inbox = (bool) $input->getOption('inbox');
        $studio = $this->studio();

        $template = trim((string) ($input->getOption('template') ?: $studio->defaultTemplate($inbox)));
        $dataFile = $input->getOption('data-file');
        if (null === $dataFile || '' === trim((string) $dataFile)) {
            $dataFile = $studio->defaultDataFile($inbox);
        }
        $dataFile = $studio->resolveDataFile(null === $dataFile ? null : (string) $dataFile);

        $translations = trim((string) ($input->getOption('translations') ?: $studio->defaultTranslations($inbox)));
        $translationsPath = $studio->resolveTranslationsPath($translations);

        $languageOption = $input->getOption('language');
        $language = null !== $languageOption && '' !== trim((string) $languageOption)
            ? trim((string) $languageOption)
            : $studio->defaultLanguage($inbox);

        $deputyOption = trim((string) ($input->getOption('deputy-email') ?? ''));
        if ('' !== $deputyOption && false === filter_var($deputyOption, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Invalid deputy email: %s', $deputyOption));
        }

        $start = trim((string) ($input->getOption('start-date') ?? ''));
        $end = trim((string) ($input->getOption('end-date') ?? ''));
        $inclusiveOption = $input->getOption('inclusive');
        $inclusive = null === $inclusiveOption ? $studio->defaultInclusive($inbox) : (bool) $inclusiveOption;

        $logoOption = trim((string) ($input->getOption('logo-url') ?? ''));

        return new TemplateRenderRequest(
            $studio->resolveTemplatePath($template),
            $dataFile,
            $translationsPath,
            '' === $logoOption ? $studio->defaultLogoUrl() : $logoOption,
            null === $language ? null : $language,
            '' === $deputyOption ? null : $deputyOption,
            DateNormalizer::normalize('' === $start ? 'today 00:00' : $start),
            DateNormalizer::normalize('' === $end ? '+7 days' : $end),
            $inclusive,
        );
    }

    protected function fail(OutputInterface $output, \Throwable $error): int
    {
        $output->writeln(sprintf('<error>%s</error>', $error->getMessage()));

        return self::FAILURE;
    }

    protected function openTarget(string $target): bool
    {
        $command = match (PHP_OS_FAMILY) {
            'Windows' => ['cmd', '/c', 'start', '', $target],
            'Darwin' => ['open', $target],
            default => ['xdg-open', $target],
        };

        try {
            $process = new Process($command);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
