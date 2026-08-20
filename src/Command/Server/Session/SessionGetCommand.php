<?php

declare(strict_types=1);

namespace App\Command\Server\Session;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:session:get', description: 'Show one control-panel session (the API has no single-session read; the list is filtered)')]
final class SessionGetCommand extends AbstractPleadCommand
{
    protected function configure(): void
    {
        $this->addArgument('session-id', InputArgument::REQUIRED, 'Session id to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sessionId = (string) $input->getArgument('session-id');

        try {
            foreach ($this->context()->gateway()->listSessions() as $session) {
                if ($session['id'] === $sessionId) {
                    $output->writeln(sprintf('ID:           %s', $session['id']));
                    $output->writeln(sprintf('Login:        %s', $session['login']));
                    $output->writeln(sprintf('Type:         %s', $session['type']));
                    $output->writeln(sprintf('IP address:   %s', $session['ip_address']));
                    $output->writeln(sprintf('Login time:   %s', $session['login_time']));
                    $output->writeln(sprintf('Idle since:   %s', $session['idle']));

                    return self::SUCCESS;
                }
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        $output->writeln(sprintf('No session with id <info>%s</info> found on the server.', $sessionId));

        return self::SUCCESS;
    }
}
