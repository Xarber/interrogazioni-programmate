<?php

declare(strict_types=1);

function testAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function testSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function testThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

function removeTestDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) removeTestDirectory($path);
        else unlink($path);
    }
    rmdir($directory);
}

