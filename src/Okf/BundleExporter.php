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
 * Artifact Concept file per collected artifact, synthesized Domain and Flow
 * Concepts grouping artifacts that share an annotation value, one ADR
 * Concept per locally declared ADR, and a bundle.json index. Never rescans
 * the application — the manifest passed in is the sole input.
 *
 * Staleness is a filesystem-comparison concern that belongs to the calling
 * command (LaravelNecromancer\Commands\Concerns\ReadsManifest already owns
 * it and needs the container's basePath()); this class stays framework-free
 * and simply accepts the precomputed boolean. $basePath is only used to
 * resolve and read locally declared ADR files.
 */
final readonly class BundleExporter
{
    public function __construct(
        private ArtifactConceptBuilder $builder = new ArtifactConceptBuilder,
        private GroupConceptBuilder $groupBuilder = new GroupConceptBuilder,
        private AdrConceptBuilder $adrBuilder = new AdrConceptBuilder,
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
        string $basePath = '',
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
            [$concepts, $artifactCount] = $this->assembleConcepts($manifest, $generatedAt, $basePath);
        } catch (RuntimeException $e) {
            return BundleExportResult::failure($e->getMessage());
        }

        try {
            $this->writeAtomically($outputPath, $concepts, $artifactCount, $generatedAt);
        } catch (Throwable $e) {
            return BundleExportResult::failure("Failed to write the bundle: {$e->getMessage()}");
        }

        return BundleExportResult::success($outputPath, $artifactCount);
    }

    /**
     * Orchestrates every concept family in the order links depend on: first
     * every artifact's cheap identity (id/title/filename), then the local
     * ADR index (which can fail the whole export before anything is
     * written), then the fully rendered Artifact Concepts — now able to
     * link relationship fields and declared adrs — and finally the
     * synthesized Domain/Flow/ADR concepts that reference those artifacts.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{0: list<ArtifactConcept>, 1: int} concepts, and how many of them are Artifact Concepts
     */
    private function assembleConcepts(array $manifest, string $generatedAt, string $basePath): array
    {
        [$classIndex, $identities] = $this->indexManifest($manifest);
        $adrIndex = $this->resolveAdrIndex($manifest, $basePath);
        $groupIndex = $this->buildGroupIndex($identities);

        $artifactConcepts = $this->buildArtifactConcepts($manifest, $generatedAt, $classIndex, $adrIndex, $groupIndex);
        $groupConcepts = $this->buildGroupConcepts($identities, $generatedAt);
        $adrConcepts = $this->buildAdrConcepts($adrIndex, $identities, $basePath, $generatedAt);

        $concepts = [...$artifactConcepts, ...$groupConcepts, ...$adrConcepts];
        $this->assertNoFilenameCollisions($concepts);

        return [$concepts, count($artifactConcepts)];
    }

    /**
     * One cheap identity pass over every artifact, building:
     * - a class index (FQCN/controller → link) for relationship rendering.
     *   Middleware is excluded: one class can register globally, in a
     *   group, and under an alias, so a class name alone is ambiguous
     *   there.
     * - every artifact's identity keyed by its own id, for Domain/Flow
     *   grouping and ADR "referenced by" resolution.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{0: array<string, ConceptLink>, 1: array<string, array{type: string, link: ConceptLink, annotations: array<string, mixed>}>}
     */
    private function indexManifest(array $manifest): array
    {
        $classIndex = [];
        $identities = [];

        foreach ((array) ($manifest['artifacts'] ?? []) as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            foreach ($items as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $identity = $this->builder->identify($type, $artifact);

                if ($identity['id'] === '') {
                    continue;
                }

                $link = new ConceptLink($identity['title'], "/artifacts/{$identity['filename']}");

                $identities[$identity['id']] = [
                    'type' => $type,
                    'link' => $link,
                    'annotations' => is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [],
                ];

                if ($type === 'middleware') {
                    continue;
                }

                $key = $type === 'routes' ? ($artifact['controller'] ?? null) : ($artifact['class'] ?? null);

                if (is_string($key) && $key !== '' && ! isset($classIndex[$key])) {
                    $classIndex[$key] = $link;
                }
            }
        }

        return [$classIndex, $identities];
    }

    /**
     * Every distinct domain/flow value declared anywhere in the manifest,
     * resolved to the link of its own (not-yet-built) group concept — cheap
     * to compute up front from identity alone, so Artifact Concepts can
     * link back to their group without waiting for buildGroupConcepts() to
     * assemble full membership.
     *
     * @param  array<string, array{type: string, link: ConceptLink, annotations: array<string, mixed>}>  $identities
     * @return array<string, ConceptLink> "domain:value"/"flow:value" → link
     */
    private function buildGroupIndex(array $identities): array
    {
        $index = [];

        foreach (['domain', 'flow'] as $kind) {
            foreach ($identities as $meta) {
                $value = $meta['annotations'][$kind] ?? null;

                if (! is_string($value) || $value === '' || isset($index["{$kind}:{$value}"])) {
                    continue;
                }

                $identity = $this->groupBuilder->identify($kind, $value);
                $index["{$kind}:{$value}"] = new ConceptLink($identity['title'], "/artifacts/{$identity['filename']}");
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, ConceptLink>  $classIndex
     * @param  array<string, ConceptLink>  $adrIndex
     * @param  array<string, ConceptLink>  $groupIndex
     * @return list<ArtifactConcept>
     */
    private function buildArtifactConcepts(array $manifest, string $generatedAt, array $classIndex, array $adrIndex, array $groupIndex): array
    {
        $concepts = [];

        foreach ((array) ($manifest['artifacts'] ?? []) as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            foreach ($items as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $concepts[] = $this->builder->build($type, $artifact, $generatedAt, $classIndex, $adrIndex, $groupIndex);
            }
        }

        return $concepts;
    }

    /**
     * @param  array<string, array{type: string, link: ConceptLink, annotations: array<string, mixed>}>  $identities
     * @return list<ArtifactConcept>
     */
    private function buildGroupConcepts(array $identities, string $generatedAt): array
    {
        $concepts = [];

        foreach (['domain', 'flow'] as $kind) {
            $groups = [];

            foreach ($identities as $id => $meta) {
                $value = $meta['annotations'][$kind] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $groups[$value][$id] = $meta['link'];
            }

            ksort($groups);

            foreach ($groups as $value => $members) {
                $concepts[] = $this->groupBuilder->build($kind, $value, $members, $generatedAt);
            }
        }

        return $concepts;
    }

    /**
     * Every local (non-absolute-URI) ADR path declared anywhere in the
     * manifest, resolved and validated against $basePath up front — before
     * any Artifact Concept is built — so a missing file fails the whole
     * export cleanly instead of leaving a half-written bundle behind.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, ConceptLink> local ADR path → link
     */
    private function resolveAdrIndex(array $manifest, string $basePath): array
    {
        $index = [];

        foreach ($this->localAdrPaths($manifest) as $path) {
            $absolute = rtrim($basePath, '/').'/'.ltrim($path, '/\\');

            if (! is_file($absolute)) {
                throw new RuntimeException(
                    "Missing local ADR file '{$path}' declared in an artifact annotation. Add the file, fix the path, or remove the reference before exporting.",
                );
            }

            $identity = $this->adrBuilder->identify($path);
            $index[$path] = new ConceptLink($identity['title'], "/artifacts/{$identity['filename']}");
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function localAdrPaths(array $manifest): array
    {
        $paths = [];

        foreach ((array) ($manifest['artifacts'] ?? []) as $items) {
            foreach ((array) $items as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];

                foreach ((array) ($annotations['adrs'] ?? []) as $adr) {
                    if (is_string($adr) && $adr !== '' && ! UriReference::isAbsolute($adr) && ! in_array($adr, $paths, true)) {
                        $paths[] = $adr;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * @param  array<string, ConceptLink>  $adrIndex
     * @param  array<string, array{type: string, link: ConceptLink, annotations: array<string, mixed>}>  $identities
     * @return list<ArtifactConcept>
     */
    private function buildAdrConcepts(array $adrIndex, array $identities, string $basePath, string $generatedAt): array
    {
        $concepts = [];

        foreach (array_keys($adrIndex) as $path) {
            $referencedBy = [];

            foreach ($identities as $id => $meta) {
                if (in_array($path, (array) ($meta['annotations']['adrs'] ?? []), true)) {
                    $referencedBy[$id] = $meta['link'];
                }
            }

            $absolute = rtrim($basePath, '/').'/'.ltrim($path, '/\\');
            $content = file_get_contents($absolute);

            if ($content === false) {
                throw new RuntimeException("Unable to read local ADR file '{$path}'.");
            }

            $concepts[] = $this->adrBuilder->build($path, $content, $referencedBy, $generatedAt);
        }

        return $concepts;
    }

    /**
     * @param  list<ArtifactConcept>  $concepts
     */
    private function assertNoFilenameCollisions(array $concepts): void
    {
        $seen = [];

        foreach ($concepts as $concept) {
            if (isset($seen[$concept->filename])) {
                throw new RuntimeException(
                    "Filename collision while building the bundle: two concepts both resolved to '{$concept->filename}'.",
                );
            }

            $seen[$concept->filename] = true;
        }
    }

    /**
     * Writes the whole bundle to a temp directory first, so nothing under
     * $outputPath is touched while concept files are being generated, then
     * hands off to BundleSwap for the final move — which itself guarantees
     * a failed swap never leaves existing output destroyed.
     *
     * @param  list<ArtifactConcept>  $concepts
     */
    private function writeAtomically(string $outputPath, array $concepts, int $artifactCount, string $generatedAt): void
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
            'artifact_count' => $artifactCount,
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
