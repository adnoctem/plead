<?php

declare(strict_types=1);

namespace App\Inbox;

use App\Repository\AutoReplyRepository;
use App\Repository\MailGroupRepository;
use App\Repository\SyncLogRepository;
use Psr\Log\LoggerInterface;

/**
 * Cleans up inbox-defined absences once their end date has passed.
 *
 * Plesk expires the auto-reply itself natively (end_date), but a deputy
 * forwarding has no end date and stays active until it is removed - so the
 * watcher removes it explicitly and disables the reply row (audit trail).
 * Runs even when the inbox feature is disabled, so leftover forwardings from
 * a previous configuration still get cleaned up.
 */
final class InboxExpiry
{
    public function __construct(
        private readonly AutoReplyRepository $autoReplies,
        private readonly MailGroupRepository $forwardings,
        private readonly SyncLogRepository $syncLog,
        private readonly LoggerInterface $logger,
        private readonly bool $dryRun,
    ) {}

    /** @return int number of expired entries cleaned up */
    public function expire(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        $count = 0;
        foreach ($this->autoReplies->endedInboxEntries($now) as $row) {
            $email = (string) $row['email'];
            $deputy = trim((string) ($row['deputy_email'] ?? ''));

            $logId = $this->syncLog->logPending('auto_reply', $email, 'expire', [
                'source' => 'inbox',
                'deputy' => $deputy,
                'end' => (string) $row['end_date'],
            ]);

            if ('' !== $deputy) {
                $this->forwardings->remove($email, $deputy);
            }
            $this->autoReplies->disable($email);
            $this->syncLog->resolve($logId, $this->dryRun ? 'dry-run' : 'ok');

            $this->logger->info('Inbox absence for {email} expired; deputy forwarding {deputy} removed.', [
                'email' => $email,
                'deputy' => '' === $deputy ? '(none)' : $deputy,
            ]);

            ++$count;
        }

        return $count;
    }
}
