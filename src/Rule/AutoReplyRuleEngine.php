<?php

declare(strict_types=1);

namespace App\Rule;

use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use App\Template\AutoReplyRenderer;
use App\Util\DateNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Keeps the local desired state of config-defined auto-replies in sync: every
 * mail.autoresponder entry is (re)recorded as an intent when the rendered
 * message or the dates diverge from the stored row. The reconciler pushes the
 * intents; removing an entry from the config does NOT disable a running
 * auto-reply (disable explicitly via mail:autoresponder:set --enabled=false).
 */
final class AutoReplyRuleEngine
{
    public function __construct(
        private readonly AutoReplyRepository $repository,
        private readonly SyncLogRepository $syncLog,
        private readonly AutoReplyRenderer $renderer,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {}

    /**
     * Converge the local desired state of one config-defined auto-reply.
     * Returns true when the intent was (re)recorded; a no-op pass records
     * nothing and performs no RPCs.
     *
     * @param array<string, mixed> $entry validated mail.autoresponder entry
     */
    public function apply(array $entry): bool
    {
        $definition = AutoReplyDefinition::fromConfigEntry($entry);
        $email = $definition->email();

        $messageFile = $definition->messageFile();
        if (!is_file($messageFile) || !is_readable($messageFile)) {
            throw new \RuntimeException(sprintf('Cannot read autoresponder message file: %s', $messageFile));
        }
        $message = $this->renderer->render([
            'message' => (string) file_get_contents($messageFile),
            'date' => new \DateTimeImmutable(),
        ]);

        $existing = $this->repository->find($email);

        // Without an explicit start date the reply should run as soon as
        // possible: keep the recorded start instead of re-stamping "now" on
        // every pass (which would re-flag the row dirty indefinitely).
        $startDate = $definition->startDate() ?? ($existing['start_date'] ?? DateNormalizer::now());

        if (null !== $existing
            && $existing['message'] === $message
            && $existing['start_date'] === $startDate
            && $existing['end_date'] === $definition->endDate()) {
            return false;
        }

        $logId = $this->syncLog->logPending('auto_reply', $email, 'set');

        $this->repository->upsert($email, $message, $startDate, $definition->endDate());
        $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');

        $this->logger->info('Config-defined auto-reply {email} recorded', ['email' => $email]);

        return true;
    }

    /**
     * Apply every configured auto-reply; returns the number of entries whose
     * intent changed. A broken entry (unreadable file, render error) is
     * logged and skipped, never fatal - this runs inside the watch loop.
     *
     * @param array<int, array<string, mixed>> $entries merged mail.autoresponder entries
     */
    public function applyAll(array $entries): int
    {
        $changed = 0;
        foreach ($entries as $entry) {
            try {
                $changed += (int) $this->apply($entry);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to apply config-defined auto-reply for {email}: {error}', [
                    'email' => (string) ($entry['address'] ?? '?'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $changed;
    }
}
