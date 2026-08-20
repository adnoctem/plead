<?php

declare(strict_types=1);

namespace App\Reconciler;

use App\Gateway\PleskMailGateway;
use App\Repository\MailAliasRepository;
use App\Repository\SyncLogRepository;
use Psr\Log\LoggerInterface;

final class MailAliasReconciler
{
    public function __construct(
        private readonly MailAliasRepository $repository,
        private readonly SyncLogRepository $syncLog,
        private readonly PleskMailGateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {
    }

    /**
     * Seed the repository with the mailbox's current Plesk aliases the first
     * time plead touches it. Without this, the first incremental change would
     * treat every pre-existing alias as drift and delete it.
     */
    public function adopt(string $email): bool
    {
        if ($this->repository->hasHistory($email)) {
            return false;
        }

        foreach ($this->gateway->getAliases($email) as $alias) {
            $this->repository->upsertActive($email, $alias);
        }

        // Adoption mirrors the server, so there is nothing left to push.
        $this->repository->markListReconciled($email);
        $this->syncLog->log('mail_alias', $email, 'adopt', 'ok');

        return true;
    }

    /** @return bool true if the mailbox was changed on the Plesk side */
    public function reconcile(string $email): bool
    {
        try {
            $actual = $this->gateway->getAliases($email);
        } catch (\Throwable $e) {
            // Read failure (e.g. server unreachable): leave the mailbox dirty
            // so the next pass retries, and record the failure in the audit
            // trail.
            $this->syncLog->log('mail_alias', $email, 'read', 'error:' . $e->getMessage());
            $this->logger->error('Failed to read aliases for {email}: {error}', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $desired = $this->repository->activeAliases($email);

        $toAdd = array_values(array_diff($desired, $actual));
        $toRemove = array_values(array_diff($actual, $desired));

        if ([] === $toAdd && [] === $toRemove) {
            // No drift: the desired state is already in place, so the
            // mailbox's watcher flag is cleared (e.g. a prior partial pass
            // completed).
            if (!$this->dryRun) {
                $this->repository->markListReconciled($email);
            }

            return false;
        }

        $failed = false;
        if ([] !== $toAdd) {
            $failed = !$this->apply('add', $email, $toAdd) || $failed;
        }

        if ([] !== $toRemove) {
            $failed = !$this->apply('remove', $email, $toRemove) || $failed;
        }

        if (!$failed && !$this->dryRun) {
            // The whole diff is in place, so every alias of this mailbox
            // matches the desired state. A partial failure keeps the mailbox
            // dirty so the next pass retries the remaining diff.
            $this->repository->markListReconciled($email);
        }

        return !$failed;
    }

    /** @param string[] $aliases */
    private function apply(string $operation, string $email, array $aliases): bool
    {
        // Audit first: record the intent before the RPC.
        $logId = $this->syncLog->logPending('mail_alias', $email, $operation, [
            'aliases' => $aliases,
        ]);

        try {
            if ('add' === $operation) {
                $this->gateway->addAliases($email, $aliases);
            } else {
                $this->gateway->removeAliases($email, $aliases);
            }

            $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');

            if ('remove' === $operation && !$this->dryRun) {
                foreach ($aliases as $alias) {
                    // Records removal of server-only/leaver aliases in the
                    // history (idempotent if the intent was already stored).
                    $this->repository->remove($email, $alias);
                }
            }

            $this->logger->info('Mail alias {operation} for {email}: {aliases}', [
                'operation' => $operation,
                'email' => $email,
                'aliases' => implode(', ', $aliases),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->syncLog->resolve($logId, 'error:' . $e->getMessage());
            $this->logger->error('Failed to {operation} aliases for {email}: {error}', [
                'operation' => $operation,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
