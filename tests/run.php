#!/usr/bin/env php
<?php

declare(strict_types=1);

$tests = [__DIR__ . '/campaign_test.php', __DIR__ . '/storage_test.php'];
foreach ($tests as $test) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test), $status);
    if ($status !== 0) exit($status);
}
fwrite(STDOUT, "All tests passed.\n");

