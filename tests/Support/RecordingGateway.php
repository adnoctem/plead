<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Gateway\PleskMailGateway;
use Monolog\Logger;
use PleskX\Api\Client;

final class RecordingGateway extends PleskMailGateway
{
    /** @var string[] */
    public array $calls = [];

    /** @var string[] emails whose mutations should fail */
    public array $failFor = [];

    /** @var array<string, string[]> email => forwarding recipients */
    public array $forwarding = [];

    public function __construct(private readonly bool $dryRunMode = false)
    {
        parent::__construct(new Client('fake.local', 8443, 'https'), $this->dryRunMode, new Logger('test'));
    }

    public function setAutoresponder(string $email, string $message, string $endDate): void
    {
        $this->calls[] = $email;
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }
    }

    public function wasCalledWith(string $email): bool
    {
        return in_array($email, $this->calls, true);
    }

    public function getForwarding(string $email): array
    {
        return $this->forwarding[$email] ?? [];
    }

    public function addForwardingRecipients(string $email, array $addresses): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        $this->forwarding[$email] = array_values(array_unique(array_merge($this->forwarding[$email] ?? [], $addresses)));
    }

    public function removeForwardingRecipients(string $email, array $addresses): void
    {
        if ($this->dryRunMode) {
            return;
        }
        if (in_array($email, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        $this->forwarding[$email] = array_values(array_diff($this->forwarding[$email] ?? [], $addresses));
    }
}
