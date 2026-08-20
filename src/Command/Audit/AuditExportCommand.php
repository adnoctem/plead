<?php

declare(strict_types=1);

namespace App\Command\Audit;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'audit:export', description: 'Dump the entire audit trail to a JSON or YAML file')]
final class AuditExportCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json (default) or yaml')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Target file (default: <data dir>/audit-export-<timestamp>.<ext>)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format') ? strtolower((string) $input->getOption('format')) : 'json';
        if (!in_array($format, ['json', 'yaml'], true)) {
            $output->writeln(sprintf('<error>Unknown format: %s. Use json or yaml.</error>', $format));

            return self::FAILURE;
        }

        $entries = $this->context()->syncLogRepository()->all();

        // Decode the details column so the export carries proper objects
        // instead of nested JSON strings.
        $entries = array_map(static function (array $entry): array {
            $entry['details'] = '' !== (string) ($entry['details'] ?? '')
                ? json_decode((string) $entry['details'], true)
                : null;

            return $entry;
        }, $entries);

        if (null !== $input->getOption('output')) {
            $target = (string) $input->getOption('output');
        } else {
            $target = sprintf(
                '%s/audit-export-%s.%s',
                $this->context()->paths->dataDir(),
                date('Ymd-His'),
                'yaml' === $format ? 'yaml' : 'json',
            );
        }

        $content = 'json' === $format
            ? json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            : Yaml::dump($entries, 4, 2);

        if (false === file_put_contents($target, $content)) {
            $output->writeln(sprintf('<error>Unable to write audit export to %s.</error>', $target));

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            'Exported %d audit entr%s to <info>%s</info> (%s).',
            count($entries),
            1 === count($entries) ? 'y' : 'ies',
            $target,
            $format,
        ));

        return self::SUCCESS;
    }
}
