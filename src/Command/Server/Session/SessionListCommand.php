<?php

declare(strict_types=1);

namespace App\Command\Server\Session;

use App\Command\AbstractPleadCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'server:session:list', description: 'List currently opened control-panel sessions (live Plesk state)')]
final class SessionListCommand extends AbstractPleadCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $sessions = $this->context()->gateway()->listSessions();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        }

        if ([] === $sessions) {
            $output->writeln('No open sessions on the server.');

            return self::SUCCESS;
        }

        $output->writeln(sprintf('%-34s %-16s %-12s %-16s %s', 'ID', 'Login', 'Type', 'IP', 'Idle'));
        foreach ($sessions as $session) {
            $output->writeln(sprintf(
                '%-34s %-16s %-12s %-16s %s',
                $session['id'],
                $session['login'],
                $session['type'],
                $session['ip_address'],
                $session['idle'],
            ));
        }

        return self::SUCCESS;
    }
}
