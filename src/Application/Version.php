<?php

declare(strict_types=1);

namespace App\Application;

use Phar;

/**
 * Version resolution for source checkouts and the compiled phar.
 *
 * Box replaces the '@git-version@' placeholder below with the current git
 * tag (or commit hash) during `box compile` (see box.json). Inside the phar
 * the replaced constant is reported; source checkouts keep the application
 * constant.
 */
final class Version
{
    /** Replaced by Box during `box compile`; the fallback lives in PleadApplication. */
    private const PHAR_VERSION = '@git-version@';

    public static function current(): string
    {
        if ('' !== \Phar::running(false)) {
            return self::PHAR_VERSION;
        }

        return PleadApplication::VERSION;
    }
}
