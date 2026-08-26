#!/usr/bin/env php
<?php

declare(strict_types=1);

// Test double for the mjml CLI that always fails, like a real conversion
// error.

fwrite(STDERR, "boom\n");

exit(1);
