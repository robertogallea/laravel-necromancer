<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Projects a manifest into a deterministic OKF 0.2 Knowledge Bundle: one
 * Artifact Concept file per collected artifact, plus a bundle.json index.
 * Never rescans the application — the manifest passed in is the sole input.
 *
 * Staleness is a filesystem-comparison concern that belongs to the calling
 * command (LaravelNecromancer\Commands\Concerns\ReadsManifest already owns
 * it and needs the container's basePath()); this class stays framework-free
 * and simply accepts the precomputed boolean.
 */
final readonly class BundleExporter
{
    public function __construct(
        private ArtifactConceptBuilder $builder = new ArtifactConceptBuilder,
        private BundleSwap $swap = new BundleSwap,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function export(
        array $manifest,
        string $outputPath,
        bool $stale,
        bool $allowStale,
        bool $allowPartial,
    ): BundleExportResult {
        if ($stale && ! $allowStale) {
            return BundleExportResult::failure(
                'Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to export anyway.',
            );
        }

        $scope = is_array($manifest['meta']['scope'] ?? null) ? $manifest['meta']['scope'] : [];
        $complete = (bool) ($scope['complete'] ?? false);

        if (! $complete && ! $allowPartial) {
            return BundleExportResult::failure(
                'Manifest scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to export anyway.',
            );
        }

        $generatedAt = (string) ($manifest['meta']['generated_at'] ?? '');

        try {
            $concepts = $this->buildConcepts($manifest, $generatedAt);
        } catch (RuntimeException $e) {
            return BundleExportResult::failure($e->getMessage());
        }

        try {
            $this->writeAtomically($outputPath, $concepts, $generatedAt);
        } catch (Throwable $e) {
            return BundleExportResult::failure("Failed to write the bundle: {$e->getMessage()}");
        }

        return BundleExportResult::success($outputPath, count($concepts));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<ArtifactConcept>
     */
    private function buildConcepts(array $manifest, string $generatedAt): array
    {
        $concepts = [];
        $seenFilenames = [];

        foreach ((array) ($manifest['artifacts'] ?? []) as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            foreach ($items as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $concept = $this->builder->build($type, $artifact, $generatedAt);

                if (isset($seenFilenames[$concept->filename])) {
                    throw new RuntimeException(
                        "Filename collision while building the bundle: two artifacts both resolved to '{$concept->filename}'.",
                    );
                }

                $seenFilenames[$concept->filename] = true;
                $concepts[] = $concept;
            }
        }

        return $concepts;
    }

    /**
     * Writes the whole bundle to a temp directory first, so nothing under
     * $outputPath is touched while concept files are being generated, then
     * hands off to BundleSwap for the final move — which itself guarantees
     * a failed swap never leaves existing output destroyed.
     *
     * @param  list<ArtifactConcept>  $concepts
     */
    private function writeAtomically(string $outputPath, array $concepts, string $generatedAt): void
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

        $index = [
            'okf_version' => '0.2',
            'necromancer_schema_version' => 1,
            'generated_at' => $generatedAt !== '' ? $generatedAt : null,
            'artifact_count' => count($concepts),
        ];

        if (file_put_contents($tempPath.'/bundle.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
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
