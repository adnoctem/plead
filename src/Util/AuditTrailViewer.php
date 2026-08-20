<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive pager over the audit trail (sync_log), modeled on a classic
 * less-style list + detail view. Only runs when $interactive is true; the
 * caller must gate on a real TTY on both stdin and stdout (non-Windows).
 * The non-interactive path degrades to a plain table, safe for pipes and
 * CI.
 *
 * Keys: ↑/↓ (or j/k) move, PgUp/PgDn, Enter opens the detail view,
 * q/Esc quits, ESC in the detail view returns to the list.
 */
final class AuditTrailViewer
{
    private const CLEAR = "\e[2J\e[H";
    private const CURSOR_HIDE = "\e[?25l";
    private const CURSOR_SHOW = "\e[?25h";
    private const REVERSE_ON = "\e[7m";
    private const REVERSE_OFF = "\e[27m";

    /** @param array<int, array<string, string>> $entries newest-first */
    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $interactive,
        private readonly array $entries,
    ) {
    }

    public function run(): int
    {
        if (!$this->interactive) {
            $this->renderPlain();

            return 0;
        }

        return $this->runInteractive();
    }

    private function renderPlain(): void
    {
        if ([] === $this->entries) {
            $this->output->writeln('The audit trail is empty.');

            return;
        }

        $this->output->writeln(sprintf('%d audit entr%s:', count($this->entries), 1 === count($this->entries) ? 'y' : 'ies'));
        $this->output->writeln(sprintf('%8s  %-24s  %-16s  %-10s  %-12s  %s', 'ID', 'When', 'Type', 'Action', 'Result', 'Resource'));
        foreach ($this->entries as $entry) {
            $this->output->writeln(sprintf(
                '%8d  %-24s  %-16s  %-10s  %-12s  %s',
                (int) $entry['id'],
                $entry['occurred_at'],
                $entry['resource_type'],
                $entry['action'],
                $entry['result'],
                $entry['resource_id'],
            ));
        }
    }

    private function runInteractive(): int
    {
        $rawMode = $this->enterRawMode();
        $this->output->write(self::CURSOR_HIDE);

        $selected = 0;
        $offset = 0;
        $detail = false;
        $height = max(10, $this->terminalLines() - 1);
        $keys = $this->keyNames();

        try {
            while (true) {
                if ($detail) {
                    $this->renderDetail($this->entries[$selected] ?? null);
                } else {
                    [$offset, $selected] = $this->renderList($selected, $offset, $height);
                }

                $key = $this->readKey();
                if ('quit' === $key) {
                    break;
                }

                if ($detail) {
                    // Only ESC returns to the list; every other key (except
                    // q) is ignored while the detail view is open.
                    if ('back' === $key) {
                        $detail = false;
                    }

                    continue;
                }

                switch ($key) {
                    case 'up':
                        $selected = max(0, $selected - 1);
                        break;
                    case 'down':
                        $selected = min(count($this->entries) - 1, $selected + 1);
                        break;
                    case 'page-up':
                        $selected = max(0, $selected - $height);
                        break;
                    case 'page-down':
                        $selected = min(count($this->entries) - 1, $selected + $height);
                        break;
                    case 'home':
                        $selected = 0;
                        break;
                    case 'end':
                        $selected = count($this->entries) - 1;
                        break;
                    case 'enter':
                        $detail = true;
                        break;
                }
            }
        } finally {
            $this->output->write(self::CLEAR . self::CURSOR_SHOW);
            if ($rawMode) {
                $this->leaveRawMode();
            }
        }

        return 0;
    }

    /** @return array{0: int, 1: int} [offset, selected] kept for state continuity */
    private function renderList(int $selected, int $offset, int $height): array
    {
        $total = count($this->entries);
        $window = min($total, $height - 1);

        // Keep the cursor inside the visible window.
        $offset = min($offset, max(0, $total - $window));
        if ($selected < $offset) {
            $offset = $selected;
        }
        if ($selected >= $offset + $window) {
            $offset = $selected - $window + 1;
        }

        $lines = [sprintf('Audit trail - %d entr%s  (arrow keys: move, Enter: detail, q: quit)', $total, 1 === $total ? 'y' : 'ies')];
        for ($i = 0; $i < $window; $i++) {
            $entry = $this->entries[$offset + $i];
            $row = sprintf(
                '%6d  %-16s  %-10s  %-12s  %s',
                (int) $entry['id'],
                $entry['resource_type'],
                $entry['action'],
                $entry['result'],
                $this->truncate($entry['resource_id'], max(10, $this->terminalColumns() - 66)),
            );
            $lines[] = $offset + $i === $selected ? self::REVERSE_ON . $row . self::REVERSE_OFF : $row;
        }

        $this->renderFrame($lines);

        return [$offset, $selected];
    }

    private function renderDetail(?array $entry): void
    {
        $lines = ['Detail - ESC: back, q: quit'];
        if (null === $entry) {
            $lines[] = '(no entry selected)';
        } else {
            $lines[] = sprintf('ID:          %d', (int) $entry['id']);
            $lines[] = sprintf('When:        %s', $entry['occurred_at']);
            $lines[] = sprintf('Type:        %s', $entry['resource_type']);
            $lines[] = sprintf('Resource:    %s', $entry['resource_id']);
            $lines[] = sprintf('Action:      %s', $entry['action']);
            $lines[] = sprintf('Result:      %s', $entry['result']);
            if ('' !== (string) ($entry['details'] ?? '')) {
                $lines[] = sprintf('Details:     %s', $entry['details']);
            }
        }
        $this->renderFrame($lines);
    }

    /** @param string[] $lines */
    private function renderFrame(array $lines): void
    {
        $this->output->write(self::CLEAR . implode("\n", $lines) . "\n");
    }

    private function readKey(): string
    {
        $first = fread(STDIN, 1);
        if (false === $first || '' === $first) {
            return 'quit';
        }

        if ("\x1b" !== $first) {
            return match ($first) {
                'q', 'Q' => 'quit',
                "\r", "\n" => 'enter',
                "\x7f", "\x08" => 'back',
                default => 'other',
            };
        }

        // Escape sequence: collect up to 3 more bytes without blocking.
        $rest = '';
        for ($i = 0; $i < 3; $i++) {
            $read = [STDIN];
            $write = null;
            $except = null;
            if (!stream_select($read, $write, $except, 0, 50000)) {
                break;
            }
            $byte = fread(STDIN, 1);
            if (false === $byte || '' === $byte) {
                break;
            }
            $rest .= $byte;
        }

        return $this->keyNames()[$first . $rest] ?? 'other';
    }

    /** @return array<string, string> escape/plain sequences to semantic keys */
    private function keyNames(): array
    {
        return [
            "\x1b" => 'back',
            "\x1b[A" => 'up',
            "\x1b[B" => 'down',
            "\x1b[5~" => 'page-up',
            "\x1b[6~" => 'page-down',
            "\x1b[1~" => 'home',
            "\x1b[4~" => 'end',
            "\x1b[3~" => 'other',
            'k' => 'up',
            'j' => 'down',
        ];
    }

    private function truncate(string $value, int $width): string
    {
        if (strlen($value) <= $width) {
            return $value;
        }

        return substr($value, 0, max(1, $width - 1)) . '…';
    }

    private function terminalLines(): int
    {
        $lines = (int) trim((string) shell_exec('tput lines 2>/dev/null'));

        return $lines > 0 ? $lines : 24;
    }

    private function terminalColumns(): int
    {
        $columns = (int) trim((string) shell_exec('tput cols 2>/dev/null'));

        return $columns > 0 ? $columns : 80;
    }

    private function enterRawMode(): bool
    {
        $output = (string) shell_exec('stty -icanon -echo 2>/dev/null');

        return false !== $output;
    }

    private function leaveRawMode(): void
    {
        shell_exec('stty icanon echo 2>/dev/null');
    }
}
