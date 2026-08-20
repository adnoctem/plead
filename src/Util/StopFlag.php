<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Mutable stop flag shared between the watch loop and its pcntl signal
 * handlers. A dedicated object keeps the flag observable to static analysis
 * as a plain bool - a local mutated only from closures would look constant.
 */
final class StopFlag
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }
}
