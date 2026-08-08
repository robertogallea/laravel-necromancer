<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Writes a set of concepts plus a bundle.json index to $outputPath safely:
 * the whole bundle is built in a temp directory first, so nothing under
 * $outputPath is touched while concept files are being generated, then
 * BundleSwap performs the final move — which itself guarantees a failed
 * swap never leaves existing output destroyed. Shared by BundleExporter
 * (the deterministic bundle) and LaravelNecromancer\Okf\Enrichment\
 * BundleEnricher (the enriched sibling bundle), so both get the identical
 * write-safety guarantee from one tested implementation.
 */
final readonly class AtomicBundleWriter
{
    public function __construct(
        private BundleSwap $swap = new BundleSwap,
    ) {}

    /**
     * $index is merged after the two fields every bundle.json always
     * carries (`okf_version`, `necromancer_schema_version`) — callers
     * supply the rest (e.g. `artifact_count`, or `concept_count`/
     * `cached_count`/`fresh_count` for an enriched bundle).
     *
     * @param  list<ArtifactConcept>  $concepts
     * @param  array<string, mixed>  $index
     */
    public function write(string $outputPath, array $concepts, array $index): void
    {
        $tempPath = rtrim($outputPath, '/').'.tmp';
        $this->removePath($tempPath);

        $artifactsDir = $tempPath.'/artifacts';

        if (! mkdir($artifactsDir, 0755, true) && ! is_dir($artifactsDir)) {
            throw new RuntimeException("Unable to create temporary bundle directory at {$tempPath}.");
        }

        foreach ($concepts as $concept) {
            if (file_put_contents($artifactsDir.'/'.$concept->filename, $concept->content."\n") === false) {
                $this->removePath($tempPath);

                throw new RuntimeException("Unable to write {$concept->filename}.");
            }
        }

        $payload = ['okf_version' => '0.2', 'necromancer_schema_version' => 1, ...$index];

        if (file_put_contents($tempPath.'/bundle.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
            $this->removePath($tempPath);

            throw new RuntimeException('Unable to write bundle.json.');
        }

        try {
            $this->swap->swap($tempPath, $outputPath);
        } catch (RuntimeException $e) {
            $this->removePath($tempPath);

            throw $e;
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
