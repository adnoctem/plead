<?php

declare(strict_types=1);

namespace App\Inbox;

use App\Gateway\PleskMailGateway;
use App\Reconciler\MailGroupReconciler;
use App\Repository\AutoReplyRepository;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use App\Template\AutoReplyRenderer;
use Psr\Log\LoggerInterface;

/**
 * Ingests absence JSON files dropped into the inbox directory by external
 * tools (directory scripts, HR exports, hand-written files - no AD
 * dependency). Every file describes one absence and is applied with the
 * audit-first model: record the intent, render the reply, queue the deputy
 * forwarding, then archive the file.
 *
 * Files are moved to `processed/` on success and to `failed/` (with a
 * `.error.txt` sidecar) when they are malformed or name an unknown mailbox.
 * Render/RPC failures leave the file in place so the next pass retries.
 */
final class InboxProcessor
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        private readonly InboxSettings $settings,
        private readonly AutoReplyRepository $autoReplies,
        private readonly MailGroupRepository $forwardings,
        private readonly MailGroupReconciler $forwardingReconciler,
        private readonly SyncLogRepository $syncLog,
        private readonly PleskMailGateway $gateway,
        private readonly AutoReplyRenderer $autoreplyRenderer,
        private readonly AutoReplyRenderer $forwardingRenderer,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {}

    /** @return int number of entries whose intent changed */
    public function process(): int
    {
        if (!is_dir($this->settings->directory)) {
            $this->logger->warning('Inbox directory {dir} does not exist; nothing to process.', [
                'dir' => $this->settings->directory,
            ]);

            return 0;
        }

        $files = glob($this->settings->directory.'/*.json') ?: [];
        sort($files);

        $changed = 0;
        foreach ($files as $path) {
            try {
                $changed += $this->processFile($path);
            } catch (\Throwable $e) {
                // Unexpected failure (render error, unreachable server, ...):
                // keep the file so the next pass retries it.
                $this->logger->error('Failed to process inbox file {file}: {error}', [
                    'file' => basename($path),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $changed;
    }

    private function processFile(string $path): int
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            $this->reject($path, 'file is not readable');

            return 0;
        }

        if (strlen($contents) > self::MAX_BYTES) {
            $this->reject($path, sprintf('file exceeds the maximum size of %d bytes', self::MAX_BYTES));

            return 0;
        }

        try {
            $trimmed = ltrim(self::stripBom($contents));
            if (!str_starts_with($trimmed, '{')) {
                $this->reject($path, 'expected a JSON object at the top level');

                return 0;
            }

            $payload = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->reject($path, 'invalid JSON: '.$e->getMessage());

            return 0;
        }

        if (!is_array($payload)) {
            $this->reject($path, 'expected a JSON object at the top level');

            return 0;
        }

        try {
            $entry = InboxEntry::fromPayload($payload, $this->settings->domain, $this->settings->timezone);
        } catch (\InvalidArgumentException $e) {
            $this->reject($path, $e->getMessage());

            return 0;
        }

        try {
            $mailbox = $this->gateway->getMailboxInfo($entry->email);
        } catch (\Throwable $e) {
            // Read failure (e.g. server unreachable): retry next pass.
            $this->logger->error('Unable to verify mailbox {email} for inbox file {file}: {error}', [
                'email' => $entry->email,
                'file' => basename($path),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        if (null === $mailbox) {
            $this->reject($path, sprintf('mailbox %s does not exist on the server.', $entry->email));

            return 0;
        }

        $changed = InboxEntry::ACTION_CANCEL === $entry->action
            ? $this->cancel($entry)
            : $this->schedule($entry);

        $this->archive($path);

        return $changed;
    }

    private function schedule(InboxEntry $entry): int
    {
        $existing = $this->autoReplies->find($entry->email);
        $previousDeputy = null !== $existing ? trim((string) ($existing['deputy_email'] ?? '')) : '';

        // A new entry supersedes an older inbox run: drop a deputy it no
        // longer carries. Expiry already disables the reply, but forwarding
        // is explicit and has to be removed by hand.
        if ('' !== $previousDeputy && $previousDeputy !== $entry->deputyEmail) {
            $this->forwardings->remove($entry->email, $previousDeputy);
        }

        $renderer = null !== $entry->deputyEmail ? $this->forwardingRenderer : $this->autoreplyRenderer;
        $message = $renderer->renderMessage(
            $this->settings->dataFile,
            $entry->startDate,
            $entry->endDate,
            $entry->inclusive ?? $this->settings->inclusive,
            $entry->language ?? $this->settings->language,
            array_merge($entry->data, ['deputyEmail' => $entry->deputyEmail ?? '']),
        );

        $format = $entry->format ?? $this->settings->format;
        if (null === $format) {
            // MJML templates render HTML; store the matching content type so
            // Plesk does not send markup as text/plain.
            $format = str_starts_with(ltrim($message), '<') ? 'html' : '';
        }

        $logId = $this->syncLog->logPending('auto_reply', $entry->email, 'set', [
            'source' => 'inbox',
            'start' => $entry->startDate,
            'end' => $entry->endDate,
            'deputy' => $entry->deputyEmail,
        ]);

        $this->autoReplies->upsert(
            $entry->email,
            $message,
            (string) $entry->startDate,
            (string) $entry->endDate,
            $entry->subject ?? $this->settings->subject ?? '',
            $format,
            $entry->deputyEmail ?? '',
        );

        if (null !== $entry->deputyEmail) {
            // Preserve forwarding that already existed on the mailbox: adopt
            // imports it on first touch, the deputy is added on top and only
            // the deputy is removed again on expiry.
            $this->forwardingReconciler->adopt($entry->email);
            $this->forwardings->upsertActive($entry->email, $entry->deputyEmail);
        }

        $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');

        $this->logger->info('Inbox absence applied for {email} ({mode})', [
            'email' => $entry->email,
            'mode' => null !== $entry->deputyEmail ? 'forwarded to '.$entry->deputyEmail : 'out of office',
        ]);

        return 1;
    }

    private function cancel(InboxEntry $entry): int
    {
        $existing = $this->autoReplies->find($entry->email);
        $deputy = null !== $existing ? trim((string) ($existing['deputy_email'] ?? '')) : '';

        if (null === $existing || ('disabled' === $existing['status'] && '' === $deputy)) {
            $this->logger->info('Inbox cancel for {email}: no active absence to cancel.', [
                'email' => $entry->email,
            ]);

            return 0;
        }

        $logId = $this->syncLog->logPending('auto_reply', $entry->email, 'cancel', ['source' => 'inbox']);
        if ('' !== $deputy) {
            $this->forwardings->remove($entry->email, $deputy);
        }
        $this->autoReplies->disable($entry->email);
        $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');

        $this->logger->info('Inbox absence cancelled for {email}.', ['email' => $entry->email]);

        return 1;
    }

    private function archive(string $path): void
    {
        // Dry-run leaves the input in place so a real run processes it again.
        if ($this->dryRun) {
            return;
        }

        $this->move($path, $this->settings->processedDirectory);
    }

    private function reject(string $path, string $message): void
    {
        $this->logger->error('Inbox file {file} rejected: {error}', [
            'file' => basename($path),
            'error' => $message,
        ]);

        if ($this->dryRun) {
            return;
        }

        $target = $this->move($path, $this->settings->failedDirectory);
        if (null !== $target) {
            @file_put_contents($target.'.error.txt', $message."\n");
        }
    }

    private function move(string $path, string $directory): ?string
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $this->logger->error('Unable to create inbox archive directory {dir}.', ['dir' => $directory]);

            return null;
        }

        $target = $directory.'/'.basename($path);
        $suffix = 0;
        while (file_exists($target)) {
            $target = $directory.'/'.pathinfo($path, PATHINFO_FILENAME).'-'.(++$suffix).'.json';
        }

        if (!@rename($path, $target)) {
            // Cross-device moves (e.g. a network share) cannot rename.
            if (!@copy($path, $target) || !@unlink($path)) {
                $this->logger->error('Unable to move inbox file {file} to {dir}.', [
                    'file' => basename($path),
                    'dir' => $directory,
                ]);

                return null;
            }
        }

        return $target;
    }

    /** Generators may still emit a UTF-8 BOM; json_decode chokes on it. */
    private static function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
