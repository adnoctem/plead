#!/usr/bin/env php
<?php

declare(strict_types=1);

// Test double for the sqlite3 CLI: opening a database file creates it (like
// the real shell does). The path passed as $argv[1] can additionally be
// recorded for assertions via the PLEAD_SQLITE3_MARKER environment variable.

$database = (string) ($argv[1] ?? '');

if ('' !== $database) {
    file_put_contents($database, '');
}

$marker = getenv('PLEAD_SQLITE3_MARKER');
if (false !== $marker && '' !== $marker) {
    file_put_contents($marker, $database);
}
