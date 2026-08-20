<?php

declare(strict_types=1);

namespace App\Command\Server\Session;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:session:terminate', description: 'Close a control-panel session')]
final class SessionTerminateCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('session-id', InputArgument::REQUIRED, 'Session id to terminate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = (string) $input->getArgument('session-id');
        $context = $this->context();

        // Audit first: the termination is recorded in the sync log.
        $logId = $context->syncLogRepository()->logPending('server_session', $sessionId, 'terminate');

        try {
            $context->gateway()->terminateSession($sessionId);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');
            $output->writeln($context->dryRun()
                ? sprintf('Session <info>%s</info> would be terminated (dry-run).', $sessionId)
                : sprintf('Session <info>%s</info> terminated.', $sessionId));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
