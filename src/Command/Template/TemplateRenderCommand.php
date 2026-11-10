<?php

declare(strict_types=1);

namespace App\Command\Template;

use App\Template\RenderedTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'template:render', description: 'Render one autoresponder template to MJML, HTML or text')]
final class TemplateRenderCommand extends AbstractTemplateCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: mjml, html or text (default: html)', 'html')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the selected format to this file')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Write preview.mjml/preview.html/preview.txt as applicable')
            ->addOption('open', null, InputOption::VALUE_NONE, 'Open the rendered HTML file in the browser')
        ;
        $this->configureTemplateOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $studio = $this->studio();
            $request = $this->request($input);
            $rendered = $studio->render($request);
        } catch (\Throwable $e) {
            return $this->fail($output, $e);
        }

        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['mjml', 'html', 'text'], true)) {
            $output->writeln('<error>--format must be "mjml", "html" or "text".</error>');

            return self::FAILURE;
        }

        if ('mjml' === $format && !$rendered->isMjml()) {
            $output->writeln('<error>This template renders text, not MJML.</error>');

            return self::FAILURE;
        }

        if ('text' === $format && $rendered->isMjml()) {
            $output->writeln('<error>This template renders MJML; use --format=mjml or --format=html.</error>');

            return self::FAILURE;
        }

        $content = match ($format) {
            'mjml' => $rendered->source,
            'text' => $rendered->source,
            default => $rendered->html,
        };

        $outputDir = trim((string) ($input->getOption('output-dir') ?? ''));
        $outputPath = trim((string) ($input->getOption('output') ?? ''));

        if ('' !== $outputDir && '' !== $outputPath) {
            $output->writeln('<error>Use either --output or --output-dir, not both.</error>');

            return self::FAILURE;
        }

        if ('' !== $outputDir) {
            return $this->writeDirectory($output, $rendered, $outputDir, (bool) $input->getOption('open'));
        }

        if ('' !== $outputPath) {
            if (false === @file_put_contents($outputPath, $content)) {
                $output->writeln(sprintf('<error>Unable to write %s</error>', $outputPath));

                return self::FAILURE;
            }

            $output->writeln(sprintf('Wrote <info>%s</info>', $outputPath));

            if ((bool) $input->getOption('open') && !$this->openTarget($outputPath)) {
                $output->writeln(sprintf('<comment>Open this file manually: %s</comment>', $outputPath));
            }

            return self::SUCCESS;
        }

        if ((bool) $input->getOption('open')) {
            $output->writeln('<error>--open requires --output or --output-dir.</error>');

            return self::FAILURE;
        }

        $output->write($content);
        if (!str_ends_with($content, "\n")) {
            $output->writeln('');
        }

        return self::SUCCESS;
    }

    private function writeDirectory(OutputInterface $output, RenderedTemplate $rendered, string $directory, bool $open): int
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $output->writeln(sprintf('<error>Unable to create output directory: %s</error>', $directory));

            return self::FAILURE;
        }

        $directory = rtrim($directory, '/\\');
        $files = [];

        if ($rendered->isMjml()) {
            $files['preview.mjml'] = $rendered->source;
            $files['preview.html'] = $rendered->html;
        } else {
            $files['preview.txt'] = $rendered->source;
            $files['preview.html'] = '<!doctype html><html><body style="margin:0;padding:24px;background:#fff;color:#111;font:15px/1.55 ui-monospace,Consolas,monospace"><pre style="white-space:pre-wrap;overflow-wrap:anywhere">'
                .htmlspecialchars($rendered->source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'</pre></body></html>';
        }

        foreach ($files as $name => $content) {
            $path = $directory.'/'.$name;
            if (false === @file_put_contents($path, $content)) {
                $output->writeln(sprintf('<error>Unable to write %s</error>', $path));

                return self::FAILURE;
            }
            $output->writeln(sprintf('Wrote <info>%s</info>', $path));
        }

        if ($open && !$this->openTarget($directory.'/preview.html')) {
            $output->writeln(sprintf('<comment>Open this file manually: %s</comment>', $directory.'/preview.html'));
        }

        return self::SUCCESS;
    }
}
