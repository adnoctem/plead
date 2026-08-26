#!/usr/bin/env php
<?php

declare(strict_types=1);

// Test double for the sqlite3 CLI: opening a database file creates it (like
// the real shell does) and stamps the path it received into the file, so
// tests can verify that the right database path reached the binary.

$database = (string) ($argv[1] ?? '');

if ('' === $database) {
    fwrite(STDERR, "sqlite3: no database file given\n");

    exit(2);
}

file_put_contents($database, sprintf("sqlite3 fake: opened %s\n", $database), FILE_APPEND);
