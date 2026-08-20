<?php

declare(strict_types=1);

namespace App\Reconciler;

use App\Gateway\PleskMailGateway;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use Psr\Log\LoggerInterface;

final class MailGroupReconciler
{
    public function __construct(
        private readonly MailGroupRepository $repository,
        private readonly SyncLogRepository $syncLog,
        private readonly PleskMailGateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {
    }

    /**
     * @return int number of lists changed this pass
     */
    public function reconcileAll(bool $full = false): int
    {
        // Default: only lists with unreconciled intents. --full sweeps every
        // managed list to catch drift introduced on the server side.
        $lists = $full ? $this->repository->managedLists() : $this->repository->unreconciledLists();

        $changed = 0;
        foreach ($lists as $listEmail) {
            $changed += (int) $this->reconcile($listEmail);
        }

        return $changed;
    }

    /**
     * Seed the repository with the group's current Plesk recipients the first
     * time plead touches a list. Without this, the first incremental change
     * would treat every pre-existing recipient as drift and delete it.
     */
    public function adopt(string $listEmail): bool
    {
        if ($this->repository->hasHistory($listEmail)) {
            return false;
        }

        foreach ($this->gateway->getForwarding($listEmail) as $address) {
            $this->repository->upsertActive($listEmail, $address);
        }

        // Adoption mirrors the server, so there is nothing left to push.
        $this->repository->markListReconciled($listEmail);
        $this->syncLog->log('mail_group', $listEmail, 'adopt', 'ok');

        return true;
    }

    /** @return bool true if the list was changed on the Plesk side */
    public function reconcile(string $listEmail): bool
    {
        try {
            $actual = $this->gateway->getForwarding($listEmail);
        } catch (\Throwable $e) {
            // Read failure (e.g. server unreachable): leave the list dirty so
            // the watcher retries, and record the failure in the audit trail.
            $this->syncLog->log('mail_group', $listEmail, 'read', 'error:' . $e->getMessage());
            $this->logger->error('Failed to read forwarding for {list}: {error}', [
                'list' => $listEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $desired = $this->repository->activeRecipients($listEmail);

        $toAdd = array_values(array_diff($desired, $actual));
        $toRemove = array_values(array_diff($actual, $desired));

        if ([] === $toAdd && [] === $toRemove) {
            // No drift: the desired state is already in place, so the list's
            // watcher flag is cleared (e.g. a prior partial pass completed).
            if (!$this->dryRun) {
                $this->repository->markListReconciled($listEmail);
            }

            return false;
        }

        $failed = false;
        if ([] !== $toAdd) {
            $failed = !$this->apply('add', $listEmail, $toAdd) || $failed;
        }

        if ([] !== $toRemove) {
            $failed = !$this->apply('remove', $listEmail, $toRemove) || $failed;
        }

        if (!$failed && !$this->dryRun) {
            // The whole diff is in place, so every recipient of this list
            // matches the desired state. A partial failure keeps the list
            // dirty so the watcher retries the remaining diff.
            $this->repository->markListReconciled($listEmail);
        }

        return !$failed;
    }

    /** @param string[] $addresses */
    private function apply(string $operation, string $listEmail, array $addresses): bool
    {
        // Audit first: record the intent before the RPC.
        $logId = $this->syncLog->logPending('mail_group', $listEmail, $operation);

        try {
            if ('add' === $operation) {
                $this->gateway->addForwardingRecipients($listEmail, $addresses);
            } else {
                $this->gateway->removeForwardingRecipients($listEmail, $addresses);
            }

            $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');

            if ('remove' === $operation && !$this->dryRun) {
                foreach ($addresses as $address) {
                    // Records removal of server-only/leaver addresses in the
                    // history (idempotent if the intent was already stored).
                    $this->repository->remove($listEmail, $address);
                }
            }

            $this->logger->info('Mail group {operation} for {list}: {addresses}', [
                'operation' => $operation,
                'list' => $listEmail,
                'addresses' => implode(', ', $addresses),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->syncLog->resolve($logId, 'error:' . $e->getMessage());
            $this->logger->error('Failed to {operation} recipients for {list}: {error}', [
                'operation' => $operation,
                'list' => $listEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
