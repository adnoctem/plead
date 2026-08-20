<?php

declare(strict_types=1);

namespace App\Command\Server;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:exec', description: 'Execute a Plesk CLI command via the REST CLI-gate (separate args with --, e.g. server:exec extension -- --call sslit --help)')]
final class ServerExecCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'CLI command id from server:ref, e.g. extension, domain, mail')
            ->addArgument('args', InputArgument::IS_ARRAY, 'Command arguments passed through verbatim (use -- before them if they start with -)')
            ->addOption('no-fail-on-error', null, InputOption::VALUE_NONE, 'Return the exit code instead of failing on non-zero exits');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        $args = (array) $input->getArgument('args');
        $failOnError = !(bool) $input->getOption('no-fail-on-error');

        $context = $this->context();

        // Audit first: the intent (command + arguments) is recorded verbatim.
        $logId = $context->syncLogRepository()->logPending('server_cli', $id, 'exec', [
            'id' => $id,
            'args' => $args,
        ]);

        try {
            $result = $context->restGateway()->cliCall($id, $args, $failOnError);

            $context->syncLogRepository()->resolve($logId, $context->dryRun() ? 'dry-run' : 'ok');

            if ('' !== $result['stdout']) {
                $output->writeln(rtrim($result['stdout']));
            }
            if ('' !== $result['stderr']) {
                $output->writeln(rtrim($result['stderr']), OutputInterface::VERBOSITY_VERBOSE);
            }

            if (0 !== $result['code']) {
                $output->writeln(sprintf('<error>Command exited with code %d.</error>', $result['code']));

                return self::FAILURE;
            }

            $output->writeln($context->dryRun()
                ? sprintf('CLI command <info>%s</info> would be executed (dry-run).', $id)
                : sprintf('CLI command <info>%s</info> executed.', $id));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $context->syncLogRepository()->resolve($logId, 'error:' . $e->getMessage());
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
