<?php

declare(strict_types=1);

namespace App\Command\Server\Service;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'server:service:start', description: 'Start a server service (srv_man)')]
final class ServiceStartCommand extends AbstractServiceCommand
{
    protected function operation(): string
    {
        return 'start';
    }
}
