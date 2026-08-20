<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an interactive terminal program (editor, SQLite shell, ...) with
 * direct control of the terminal.
 */
final class InteractiveProcessLauncher
{
    public function resolve(string $program): ?string
    {
        // pcntl_exec does no PATH lookup (unlike Python's subprocess), so a
        // bare name like "nvim" or "sqlite3" must be resolved to an absolute
        // path first.
        if (str_contains($program, '/')) {
            return is_executable($program) ? $program : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $program;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<string> $argv */
    public function run(array $argv, OutputInterface $output, string $label): int
    {
        if (!$this->stdinIsTerminal()) {
            // When stdin is not a terminal (pipes, cron, CI), an interactive
            // program cannot read keystrokes: it renders but appears frozen,
            // which is indistinguishable from a hang. Warn instead of
            // silently launching into that state.
            $output->writeln(sprintf(
                '<warning>stdin is not a terminal; %s may not receive keyboard input.</warning>',
                $label,
            ));
        }

        // A full-screen TUI killed instead of exited cleanly (Ctrl+C at the
        // wrong moment, kill -9, closed terminal) leaves the terminal emulator
        // with mouse/focus reporting enabled. From then on, every click or
        // focus change injects raw escape sequences into the input stream and
        // the next spawned program renders fine but never receives keystrokes.
        // Explicitly disable those modes before the program starts so it
        // always gets a clean terminal, and again afterwards so a crashed
        // program does not poison the next one.
        $this->resetTerminalModes();

        $exitCode = $this->spawn($argv);

        $this->resetTerminalModes();

        return $exitCode;
    }

    /** @param list<string> $argv */
    private function spawn(array $argv): int
    {
        if (!function_exists('pcntl_fork')) {
            // Fallback for hosts without pcntl: shell-mediated spawn with the
            // same inherited fds.
            passthru(implode(' ', array_map('escapeshellarg', $argv)), $exitCode);

            return $exitCode;
        }

        $pid = pcntl_fork();
        if (-1 === $pid) {
            // Fork failed; degrade to the shell-mediated spawn rather than fail.
            passthru(implode(' ', array_map('escapeshellarg', $argv)), $exitCode);

            return $exitCode;
        }

        if (0 === $pid) {
            // Child: replace the PHP process with the program via a direct
            // exec (no shell, no pipes, fds inherited) - the same handover
            // click's subprocess.Popen list-form or `env nvim file` perform.
            // The sh -c wrapper used by passthru breaks full-screen TUIs on
            // WSL2/Windows Terminal (ConPTY), while this spawn works.
            $binary = $this->resolve($argv[0]);
            if (null !== $binary) {
                // @ suppresses the pcntl warning; exec failure is handled
                // below by exiting with status 127.
                @pcntl_exec($binary, array_slice($argv, 1));
            }

            // exec() failed: program not found or not executable.
            exit(127);
        }

        pcntl_waitpid($pid, $status);

        if (pcntl_wifexited($status)) {
            return (int) pcntl_wexitstatus($status);
        }

        // Program was killed by a signal: mirror the shell convention of
        // exit status 128 + signal number.
        $signal = pcntl_wtermsig($status);

        return 128 + (is_int($signal) && $signal > 0 ? $signal : 0);
    }

    private function stdinIsTerminal(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    private function resetTerminalModes(): void
    {
        // The escape sequences below are only meaningful when stdout is a
        // terminal; when piped or redirected they would corrupt the output
        // stream instead, so they are skipped unless stdout is a TTY. Windows
        // consoles do not implement VT mouse/focus reporting, so this is a
        // no-op there as well.
        if ('Windows' === PHP_OS_FAMILY || !defined('STDOUT') || !stream_isatty(STDOUT)) {
            return;
        }

        // ESC [ ? 1000l / 1002l / 1003l / 1006l  -> disable mouse reporting
        // ESC [ ? 1004l                           -> disable focus reporting
        fwrite(STDOUT, "\x1b[?1000l\x1b[?1002l\x1b[?1003l\x1b[?1006l\x1b[?1004l");
        fflush(STDOUT);
    }
}
