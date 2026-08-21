<?php

declare(strict_types=1);

namespace App\Rule;

use App\Gateway\PleskMailGateway;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use Psr\Log\LoggerInterface;

/**
 * Computes the desired recipient set of rule-driven mail groups and records
 * the intents in the local state (audit-first). The actual push to Plesk is
 * done by MailGroupReconciler, which picks the intents up afterwards.
 */
final class GroupRuleEngine
{
    public function __construct(
        private readonly MailGroupRepository $repository,
        private readonly SyncLogRepository $syncLog,
        private readonly PleskMailGateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {}

    /**
     * Converge the local desired state of one rule-driven list. Returns true
     * when the computed set differed from the recorded state (intents were
     * written); a no-op pass records nothing and performs no RPCs.
     */
    public function apply(GroupRule $rule): bool
    {
        $desired = $this->computeRecipients($rule);
        $email = $rule->email();
        $current = $this->repository->activeRecipients($email);

        if ($desired === $current) {
            return false;
        }

        $logId = $this->syncLog->logPending('mail_group', $email, 'set', ['recipients' => $desired]);

        foreach ($desired as $address) {
            $this->repository->upsertActive($email, $address);
        }
        foreach ($current as $address) {
            if (!in_array($address, $desired, true)) {
                $this->repository->remove($email, $address);
            }
        }

        // The intent is recorded; the reconciler's own entries capture the RPC
        // outcome when it pushes the diff to Plesk.
        $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');
        $this->logger->info('Rule-driven mail group {list} updated to {count} recipients', [
            'list' => $email,
            'count' => count($desired),
        ]);

        return true;
    }

    /**
     * Apply every configured rule; returns the number of lists whose desired
     * state changed (intents written, pending reconciliation).
     *
     * @param array<int, array<string, mixed>> $entries merged mail.group entries
     */
    public function applyAll(array $entries): int
    {
        $changed = 0;
        foreach (GroupRuleSet::fromConfig($entries) as $rule) {
            $changed += (int) $this->apply($rule);
        }

        return $changed;
    }

    /**
     * Desired recipient set: the pattern-filtered live domain addresses (when
     * the rule has a pattern) merged with the manual recipients, lowercased,
     * deduplicated and sorted. Manual recipients are appended verbatim - they
     * may point at domains Plesk does not manage.
     *
     * @return string[] lowercased, sorted recipient set
     */
    private function computeRecipients(GroupRule $rule): array
    {
        $recipients = $rule->recipients();

        if ($rule->hasPattern()) {
            $derived = array_filter(
                $this->gateway->listAddresses($rule->domain()),
                static fn (string $address): bool => $address !== $rule->email() && $rule->matches($address),
            );
            $recipients = array_merge($derived, $recipients);
        }

        $recipients = array_values(array_unique($recipients));
        sort($recipients);

        return $recipients;
    }
}
