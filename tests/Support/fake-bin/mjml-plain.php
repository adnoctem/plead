#!/usr/bin/env php
<?php

declare(strict_types=1);

// Test double for the mjml CLI: minimal passthrough that wraps stdin in an
// <html> document.

echo '<html>', stream_get_contents(STDIN), '</html>';
