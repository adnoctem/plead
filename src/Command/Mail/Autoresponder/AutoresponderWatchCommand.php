<?php

declare(strict_types=1);

namespace App\Command\Mail\Autoresponder;

use App\Command\Mail\AbstractWatchCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mail:autoresponder:watch', description: 'Continuously apply scheduled auto-replies and pending disables as their start time is reached')]
final class AutoresponderWatchCommand extends AbstractWatchCommand
{
    protected function watchName(): string
    {
        return 'auto-replies';
    }

    protected function runPass(bool $full): int
    {
        return $this->context()->reconciler()->reconcileAll($full);
    }

    protected function passMessage(int $count): string
    {
        return sprintf('applied %d auto-repl%s', $count, 1 === $count ? 'y' : 'ies');
    }

    protected function fullOptionDescription(): string
    {
        return 'Re-verify every entry against the server to catch drift';
    }
}
