<?php

declare(strict_types=1);

namespace App\Command\Config;

use App\Command\AbstractPleadCommand;
use App\Config\ConfigFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'config:set', description: 'Set a configuration key in the user config file')]
final class ConfigSetCommand extends AbstractPleadCommand
{
    private const WRITABLE_KEYS = [
        'servers.0.host',
        'servers.0.secret_key',
        'servers.0.login',
        'servers.0.password',
        'default_server',
        'watch.interval',
        'template.auto_reply_path',
        'log_level',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Dotted configuration key (mail.group entries are edited via config:edit)')
            ->addArgument('value', InputArgument::REQUIRED, 'Value to store')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = (string) $input->getArgument('key');
        $value = (string) $input->getArgument('value');

        // Allow any servers.<index>.<field> key, not just the literal first
        // server - the whitelist above only demonstrates the common case.
        if (!in_array($key, self::WRITABLE_KEYS, true) && !$this->isServerKey($key)) {
            $output->writeln(sprintf(
                '<error>Key "%s" is not writable. Valid keys: %s</error>',
                $key,
                implode(', ', self::WRITABLE_KEYS),
            ));

            return self::FAILURE;
        }

        $typed = $this->coerce($key, $value, $output);
        if (null === $typed) {
            return self::FAILURE;
        }

        $paths = $this->context()->paths;
        $target = ConfigFile::targetFile($paths->configPaths());

        $raw = ConfigFile::read($target);

        self::nestedSet($raw, $key, $typed);

        ConfigFile::write($target, $raw);

        $output->writeln(sprintf('<info>%s</info> = <info>%s</info> written to %s', $key, $value, $target));

        return self::SUCCESS;
    }

    /**
     * config:set receives raw strings; integer-typed keys must be stored as
     * ints or the next config load fails the schema validation.
     */
    private function coerce(string $key, string $value, OutputInterface $output): int|string|null
    {
        if ('watch.interval' !== $key) {
            return $value;
        }

        if (!ctype_digit($value) || 0 === (int) $value) {
            $output->writeln('<error>watch.interval must be a positive integer.</error>');

            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $raw */
    private static function nestedSet(array &$raw, string $key, int|string $value): void
    {
        $segments = explode('.', $key);
        $current = &$raw;
        foreach ($segments as $segment) {
            $current = &$current[$segment];
        }
        $current = $value;
    }

    private function isServerKey(string $key): bool
    {
        return 1 === preg_match('/^servers\.\d+\.(host|secret_key|login|password)$/', $key);
    }
}
