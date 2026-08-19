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

    /** @return int number of lists changed this pass */
    public function reconcileAll(): int
    {
        $changed = 0;
        foreach ($this->repository->managedLists() as $listEmail) {
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

        $this->syncLog->log('mail_group', $listEmail, 'adopt', 'ok');

        return true;
    }

    /** @return bool true if the list was changed on the Plesk side */
    public function reconcile(string $listEmail): bool
    {
        $desired = $this->repository->activeRecipients($listEmail);
        $actual = $this->gateway->getForwarding($listEmail);

        $toAdd = array_values(array_diff($desired, $actual));
        $toRemove = array_values(array_diff($actual, $desired));

        if ([] === $toAdd && [] === $toRemove) {
            return false;
        }

        if ([] !== $toAdd) {
            $this->apply('add', $listEmail, $toAdd);
        }

        if ([] !== $toRemove) {
            $this->apply('remove', $listEmail, $toRemove);
        }

        return true;
    }

    /** @param string[] $addresses */
    private function apply(string $operation, string $listEmail, array $addresses): void
    {
        try {
            if ('add' === $operation) {
                $this->gateway->addForwardingRecipients($listEmail, $addresses);
            } else {
                $this->gateway->removeForwardingRecipients($listEmail, $addresses);
            }

            $result = $this->dryRun ? 'dry-run' : 'ok';
            $this->syncLog->log('mail_group', $listEmail, $operation, $result);

            if ('remove' === $operation && !$this->dryRun) {
                foreach ($addresses as $address) {
                    $this->repository->remove($listEmail, $address);
                }
            }

            $this->logger->info('Mail group {operation} for {list}: {addresses}', [
                'operation' => $operation,
                'list' => $listEmail,
                'addresses' => implode(', ', $addresses),
            ]);
        } catch (\Throwable $e) {
            $this->syncLog->log('mail_group', $listEmail, $operation, 'error:' . $e->getMessage());
            $this->logger->error('Failed to {operation} recipients for {list}: {error}', [
                'operation' => $operation,
                'list' => $listEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
