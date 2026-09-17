#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/SecureStorage.php';

$command = $argv[1] ?? 'help';
try {
    if ($command === 'generate-key') {
        $path = $argv[2] ?? (getenv('SCUOLA_STORAGE_KEY_FILE') ?: '');
        if ($path === '') throw new InvalidArgumentException('Provide a key path.');
        SecureStorage::generateKeyFile($path);
        fwrite(STDOUT, "Key generated.\n");
        exit(0);
    }
    $storage = new SecureStorage();
    if ($command === 'migrate') {
        $root = $argv[2] ?? dirname(__DIR__);
        fwrite(STDOUT, json_encode($storage->bootstrapLegacy($root), JSON_PRETTY_PRINT) . PHP_EOL);
    } elseif ($command === 'verify') {
        $classes = $storage->listAllClassIds();
        foreach ($classes as $classId) $storage->getClass($classId);
        fwrite(STDOUT, sprintf("Verified %d encrypted class databases.\n", count($classes)));
    } elseif ($command === 'export') {
        $target = $argv[2] ?? '';
        if ($target === '') throw new InvalidArgumentException('Provide a new export directory.');
        fwrite(STDOUT, json_encode($storage->exportLegacy($target), JSON_PRETTY_PRINT) . PHP_EOL);
    } else {
        fwrite(STDOUT, "Usage:\n  storage.php generate-key PATH\n  storage.php migrate [APP_ROOT]\n  storage.php verify\n  storage.php export TARGET\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
