<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\RuntimeContext;
use Symfony\Component\Console\Command\Command;

abstract class AbstractPleadCommand extends Command
{
    private ?RuntimeContext $context = null;

    public function setContext(RuntimeContext $context): void
    {
        $this->context = $context;
    }

    protected function context(): RuntimeContext
    {
        if (null === $this->context) {
            throw new \LogicException(sprintf('Command %s was not given a RuntimeContext.', static::class));
        }

        return $this->context;
    }
}
