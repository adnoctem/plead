<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Gateway\PleskRestGateway;
use Monolog\Logger;

final class RecordingRestGateway extends PleskRestGateway
{
    /** @var string[] */
    public array $cliCommandsList = [];

    /** @var array<string, array<string, mixed>> */
    public array $cliRefs = [];

    /** @var array<int, array{id: string, args: string[], failOnError: bool}> */
    public array $cliCalls = [];

    /** @var string[] command ids whose mutations should fail */
    public array $failFor = [];

    public function __construct(private readonly bool $dryRunMode = false)
    {
        parent::__construct('fake.local', 8443, 'https', 'test-key', $dryRunMode, new Logger('test'));
    }

    /** @return string[] */
    public function cliCommands(): array
    {
        return $this->cliCommandsList;
    }

    /** @return array<string, mixed> */
    public function cliRef(string $id): array
    {
        return $this->cliRefs[$id] ?? [];
    }

    /**
     * @param string[] $params
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    public function cliCall(string $id, array $params, bool $failOnError = true): array
    {
        $this->cliCalls[] = ['id' => $id, 'args' => $params, 'failOnError' => $failOnError];

        if ($this->dryRunMode) {
            return ['code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if (in_array($id, $this->failFor, true)) {
            throw new \RuntimeException('boom');
        }

        return ['code' => 0, 'stdout' => 'done: '.implode(' ', $params), 'stderr' => ''];
    }
}
