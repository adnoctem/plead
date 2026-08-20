<?php

declare(strict_types=1);

namespace App\Command\Mail\Group;

use App\Command\Mail\AbstractWatchCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mail:group:watch', description: 'Continuously converge mail group recipients toward the desired state')]
final class GroupWatchCommand extends AbstractWatchCommand
{
    protected function watchName(): string
    {
        return 'mail groups';
    }

    protected function runPass(bool $full): int
    {
        return $this->context()->reconcilerMail()->reconcileAll($full);
    }

    protected function passMessage(int $count): string
    {
        return sprintf('changed %d list%s', $count, 1 === $count ? '' : 's');
    }

    protected function fullOptionDescription(): string
    {
        return 'Re-converge every managed list to catch server-side drift';
    }
}
