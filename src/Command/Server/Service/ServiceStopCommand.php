<?php

declare(strict_types=1);

namespace App\Command\Server\Service;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'server:service:stop', description: 'Stop a server service (srv_man)')]
final class ServiceStopCommand extends AbstractServiceCommand
{
    protected function operation(): string
    {
        return 'stop';
    }
}
