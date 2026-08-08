<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Moves a fully-built temp directory into place as the real bundle output,
 * without ever leaving a good existing bundle destroyed by a failed swap.
 *
 * A naive delete-then-rename would delete the existing output first and
 * only then attempt rename($temp, $output) — if that rename fails, the old
 * bundle is already gone. Instead, the existing output (if any) is moved
 * aside to a `.bak` sibling first; if the temp-to-output rename then fails,
 * the backup is moved straight back, so a failed swap always leaves either
 * the old bundle or the new one in place, never neither.
 */
final readonly class BundleSwap
{
    public function swap(string $tempPath, string $outputPath): void
    {
        $backupPath = rtrim($outputPath, '/').'.bak';
        $hadExisting = file_exists($outputPath);

        if ($hadExisting) {
            $this->removePath($backupPath);

            if (! $this->tryRename($outputPath, $backupPath)) {
                throw new RuntimeException("Unable to move the existing bundle aside before replacing it at {$outputPath}.");
            }
        }

        if (! $this->tryRename($tempPath, $outputPath)) {
            if ($hadExisting) {
                $this->tryRename($backupPath, $outputPath);
            }

            throw new RuntimeException("Unable to move the generated bundle into place at {$outputPath}.");
        }

        if ($hadExisting) {
            $this->removePath($backupPath);
        }
    }

    /**
     * A plain rename() failure (e.g. a read-only parent directory) is an
     * expected, handled outcome here, not a bug to surface as a PHP
     * warning — the caller decides what to do with the boolean result.
     */
    private function tryRename(string $from, string $to): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return rename($from, $to);
        } finally {
            restore_error_handler();
        }
    }

    private function removePath(string $path): void
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
