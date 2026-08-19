<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use App\Config\ConfigFile;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:edit', description: 'Open the user config file in your $EDITOR')]
final class ConfigEditCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $this->context()->paths;
        $target = ConfigFile::targetFile($paths->configPaths());

        if (!is_file($target)) {
            $this->create($target);
            $output->writeln(sprintf('Created <info>%s</info>', $target));
        }

        $editor = $this->editor();
        $output->writeln(sprintf('Opening %s with %s', $target, $editor));

        passthru($editor . ' ' . escapeshellarg($target), $exitCode);
        if (0 !== $exitCode) {
            $output->writeln(sprintf('<error>Editor exited with status %d.</error>', $exitCode));

            return self::FAILURE;
        }

        try {
            $this->context()->configLoader()->load($target);
            $output->writeln('<info>Configuration is valid.</info>');
        } catch (InvalidConfigurationException | \RuntimeException $e) {
            $output->writeln(sprintf('<error>Configuration is invalid: %s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function create(string $target): void
    {
        ConfigFile::write($target, [
            'plesk' => [
                'host' => null,
                'secret_key' => null,
            ],
            'log_level' => 'info',
        ]);
    }

    private function editor(): string
    {
        $editor = getenv('EDITOR');
        if (false === $editor || '' === $editor) {
            return 'Windows' === PHP_OS_FAMILY ? 'notepad' : 'vi';
        }

        return $editor;
    }
}
