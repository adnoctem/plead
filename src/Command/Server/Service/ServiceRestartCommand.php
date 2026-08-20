<?php

declare(strict_types=1);

namespace App\Command\Server\Service;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'server:service:restart', description: 'Restart a server service (srv_man)')]
final class ServiceRestartCommand extends AbstractServiceCommand
{
    protected function operation(): string
    {
        return 'restart';
    }
}
