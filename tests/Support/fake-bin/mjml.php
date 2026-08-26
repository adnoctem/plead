#!/usr/bin/env php
<?php

declare(strict_types=1);

// Test double for the mjml CLI: wraps stdin in an HTML document like the
// real binary does for converted output.

echo '<!doctype html><html><body>', stream_get_contents(STDIN), '</body></html>';
