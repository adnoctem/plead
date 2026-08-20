<?php

declare(strict_types=1);

namespace App\Reconciler;

use App\Gateway\PleskMailGateway;
use App\Repository\AutoReplyRepository;
use App\Repository\SyncLogRepository;
use App\Util\DateNormalizer;
use Psr\Log\LoggerInterface;

final class AutoReplyReconciler
{
    public function __construct(
        private readonly AutoReplyRepository $repository,
        private readonly SyncLogRepository $syncLog,
        private readonly PleskMailGateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {
    }

    /**
     * @return int number of entries applied this pass
     */
    public function reconcileAll(bool $full = false): int
    {
        $now = new \DateTimeImmutable();
        $rows = $full ? $this->repository->dueAll($now) : $this->repository->pending($now);

        $applied = 0;
        foreach ($rows as $row) {
            $applied += (int) $this->reconcileEntry($row);
        }

        return $applied;
    }

    /** @return bool true if the entry was applied to Plesk */
    public function reconcile(string $email): bool
    {
        $row = $this->repository->find($email);
        if (null === $row) {
            throw new \RuntimeException(sprintf('No scheduled auto-reply for %s.', $email));
        }

        if ('disabled' !== $row['status'] && new \DateTimeImmutable($row['start_date']) > new \DateTimeImmutable()) {
            return false;
        }

        return $this->reconcileEntry($row);
    }

    /** @param array<string, mixed> $row */
    private function reconcileEntry(array $row): bool
    {
        $email = (string) $row['email'];

        // Audit first: record the intent before the RPC, so the trail shows
        // what was attempted even if the server is unreachable.
        $logId = $this->syncLog->logPending('auto_reply', $email, 'apply');

        try {
            if ('disabled' === $row['status']) {
                $this->gateway->disableAutoresponder($email);
            } else {
                $this->gateway->setAutoresponder($email, (string) $row['message'], (string) $row['end_date']);
            }

            if ($this->dryRun) {
                $this->syncLog->resolve($logId, 'dry-run');
                $this->logger->info('DRY-RUN: would {action} auto-reply for {email}', [
                    'action' => 'disabled' === $row['status'] ? 'disable' : 'apply',
                    'email' => $email,
                ]);

                return false;
            }

            $this->repository->markReconciled($email, DateNormalizer::now());
            $this->syncLog->resolve($logId, 'ok');
            $this->logger->info(
                'disabled' === $row['status'] ? 'Disabled auto-reply for {email}' : 'Applied auto-reply for {email}',
                ['email' => $email],
            );

            return true;
        } catch (\Throwable $e) {
            // Keep the entry dirty (reconciled = 0) so the watcher retries;
            // the error text lands in the audit trail.
            $this->syncLog->resolve($logId, 'error:' . $e->getMessage());
            $this->logger->error('Failed to apply auto-reply for {email}: {error}', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
