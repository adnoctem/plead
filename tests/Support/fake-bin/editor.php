#!/usr/bin/env php
<?php

declare(strict_types=1);

// Test double for $EDITOR: appends a line to the file it is asked to edit,
// mimicking a user adding a key to the config.

$target = (string) ($argv[1] ?? '');

if ('' === $target) {
    fwrite(STDERR, "editor: no target file given\n");

    exit(2);
}

file_put_contents($target, "log_level: debug\n", FILE_APPEND);
