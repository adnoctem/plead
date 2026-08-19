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

    /** @return int number of entries applied this pass */
    public function reconcileAll(): int
    {
        $applied = 0;
        foreach ($this->repository->due(new \DateTimeImmutable()) as $row) {
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

        if (new \DateTimeImmutable($row['start_date']) > new \DateTimeImmutable()) {
            return false;
        }

        return $this->reconcileEntry($row);
    }

    /** @param array<string, mixed> $row */
    private function reconcileEntry(array $row): bool
    {
        $email = (string) $row['email'];

        try {
            $this->gateway->setAutoresponder($email, (string) $row['message'], (string) $row['end_date']);

            if ($this->dryRun) {
                $this->syncLog->log('auto_reply', $email, 'apply', 'dry-run');
                $this->logger->info('DRY-RUN: would mark {email} as applied', ['email' => $email]);

                return false;
            }

            $this->repository->markApplied($email, DateNormalizer::now());
            $this->syncLog->log('auto_reply', $email, 'apply', 'ok');
            $this->logger->info('Applied auto-reply for {email}', ['email' => $email]);

            return true;
        } catch (\Throwable $e) {
            $this->syncLog->log('auto_reply', $email, 'apply', 'error:' . $e->getMessage());
            $this->logger->error('Failed to apply auto-reply for {email}: {error}', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
