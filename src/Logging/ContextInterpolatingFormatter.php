<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

final class ContextInterpolatingFormatter extends LineFormatter
{
    public function format(LogRecord $record): string
    {
        $record = $record->with(message: $this->interpolate($record->message, $record->context));

        return parent::format($record);
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $replace['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
