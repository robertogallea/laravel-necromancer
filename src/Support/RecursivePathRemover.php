<?php

declare(strict_types=1);

namespace LaravelNecromancer\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Deletes a file or directory tree, silently no-op-ing when nothing exists
 * at $path. Shared by every Necromancer writer that stages output in a
 * temp directory before an atomic swap (LaravelNecromancer\Okf\BundleSwap,
 * AtomicBundleWriter, and LaravelNecromancer\Graph\GraphExporter) — each
 * needs to clear a possibly-stale temp/backup path left behind by a
 * previous failed run before writing a fresh one.
 */
final class RecursivePathRemover
{
    public static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
