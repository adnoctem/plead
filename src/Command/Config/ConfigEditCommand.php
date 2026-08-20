<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use App\Config\ConfigFile;
use App\Util\InteractiveProcessLauncher;
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

        $this->warnIfSwapFileExists($target, $output);

        $editor = $this->editor();
        $output->writeln(sprintf('Opening %s with %s', $target, $editor));

        // Split on whitespace so values like "code --wait" or "vim -f" work.
        $argv = array_values(array_filter(preg_split('/\s+/', trim($editor))));
        $argv[] = $target;

        $launcher = new InteractiveProcessLauncher();
        if (null === $launcher->resolve($argv[0])) {
            $output->writeln(sprintf('<error>Editor "%s" was not found on your PATH.</error>', $argv[0]));

            return self::FAILURE;
        }

        $exitCode = $launcher->run($argv, $output, 'editor');
        if (0 !== $exitCode) {
            $output->writeln(sprintf('<error>Editor exited with status %d.</error>', $exitCode));

            return self::FAILURE;
        }

        try {
            $this->context()->configLoader()->load($target);
            $output->writeln('<info>Configuration is valid.</info>');
        } catch (InvalidConfigurationException|\RuntimeException $e) {
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

    private function warnIfSwapFileExists(string $target, OutputInterface $output): void
    {
        // Vim/Neovim write a sibling ".<name>.swp" next to a file while it is
        // open. A stale swap file means the config is either open in another
        // editor (edits would fight each other) or a previous session crashed;
        // both are worth surfacing before the user edits blindly.
        $swap = dirname($target).DIRECTORY_SEPARATOR.'.'.basename($target).'.swp';
        if (is_file($swap)) {
            $output->writeln(sprintf(
                '<comment>Stale swap file found at %s. Is the config already open in another editor?</comment>',
                $swap,
            ));
        }
    }
}
