<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

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
        private AtomicBundleWriter $writer = new AtomicBundleWriter,
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
        $scopeError = $this->validateScope($manifest, $stale, $allowStale, $allowPartial);

        if ($scopeError !== null) {
            return BundleExportResult::failure($scopeError);
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
     * The same stale/partial refusal export() applies, exposed so
     * LaravelNecromancer\Okf\Enrichment\BundleEnricher can enforce an
     * identical gate on the same manifest without duplicating the wording
     * or risking the two commands drifting on what counts as safe input.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function validateScope(array $manifest, bool $stale, bool $allowStale, bool $allowPartial): ?string
    {
        if ($stale && ! $allowStale) {
            return 'Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to export anyway.';
        }

        $scope = is_array($manifest['meta']['scope'] ?? null) ? $manifest['meta']['scope'] : [];
        $complete = (bool) ($scope['complete'] ?? false);

        if (! $complete && ! $allowPartial) {
            return 'Manifest scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to export anyway.';
        }

        return null;
    }

    /**
     * The same deterministic build export() performs, exposed without
     * writing anything and with each concept family kept separate — the
     * seam LaravelNecromancer\Okf\Enrichment\BundleEnricher needs to build
     * an enriched sibling bundle from concepts guaranteed identical to what
     * necromancer:okf produces, and to know each artifact's declared
     * annotations for building privacy-bounded prompts, without duplicating
     * any indexing/build logic and without granting the deterministic
     * exporter itself any AI awareness.
     *
     * $enrichments, when given, is keyed by canonical concept id (an
     * artifact's own id, "domain:value"/"flow:value", or "adr:path") and
     * attaches to the matching concept only — an id with no matching
     * concept is silently ignored, and a concept with no entry builds
     * exactly as it would deterministically. This is BundleEnricher's only
     * seam into concept building: it never calls a concept builder itself.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, ConceptEnrichment>  $enrichments
     * @return array{artifact: list<ArtifactConcept>, group: list<ArtifactConcept>, adr: list<ArtifactConcept>, identities: array<string, array{type: string, link: ConceptLink, annotations: array<string, mixed>}>}
     */
    public function assemble(array $manifest, string $generatedAt, string $basePath, array $enrichments = []): array
    {
        [$classIndex, $identities] = $this->indexManifest($manifest);
        $adrIndex = $this->resolveAdrIndex($manifest, $basePath);
        $groupIndex = $this->buildGroupIndex($identities);

        $assembled = [
            'artifact' => $this->buildArtifactConcepts($manifest, $generatedAt, $classIndex, $adrIndex, $groupIndex, $enrichments),
            'group' => $this->buildGroupConcepts($identities, $generatedAt, $enrichments),
            'adr' => $this->buildAdrConcepts($adrIndex, $identities, $basePath, $generatedAt, $enrichments),
            'identities' => $identities,
        ];

        $this->assertNoFilenameCollisions([...$assembled['artifact'], ...$assembled['group'], ...$assembled['adr']]);

        return $assembled;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{0: list<ArtifactConcept>, 1: int} concepts, and how many of them are Artifact Concepts
     */
    private function assembleConcepts(array $manifest, string $generatedAt, string $basePath): array
    {
        $assembled = $this->assemble($manifest, $generatedAt, $basePath);
        $concepts = [...$assembled['artifact'], ...$assembled['group'], ...$assembled['adr']];

        return [$concepts, count($assembled['artifact'])];
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
     * @param  array<string, ConceptEnrichment>  $enrichments
     * @return list<ArtifactConcept>
     */
    private function buildArtifactConcepts(array $manifest, string $generatedAt, array $classIndex, array $adrIndex, array $groupIndex, array $enrichments): array
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

                $id = (string) ($artifact['id'] ?? '');
                $concepts[] = $this->builder->build($type, $artifact, $generatedAt, $classIndex, $adrIndex, $groupIndex, $enrichments[$id] ?? null);
            }
        }

        return $concepts;
    }

    /**
     * @param  array<string, array{type: string, link: ConceptLink, annotations: array<string, mixed>}>  $identities
     * @param  array<string, ConceptEnrichment>  $enrichments
     * @return list<ArtifactConcept>
     */
    private function buildGroupConcepts(array $identities, string $generatedAt, array $enrichments): array
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
                $concepts[] = $this->groupBuilder->build($kind, $value, $members, $generatedAt, $enrichments["{$kind}:{$value}"] ?? null);
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
     * @param  array<string, ConceptEnrichment>  $enrichments
     * @return list<ArtifactConcept>
     */
    private function buildAdrConcepts(array $adrIndex, array $identities, string $basePath, string $generatedAt, array $enrichments): array
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

            $concepts[] = $this->adrBuilder->build($path, $content, $referencedBy, $generatedAt, $enrichments["adr:{$path}"] ?? null);
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
     * @param  list<ArtifactConcept>  $concepts
     */
    private function writeAtomically(string $outputPath, array $concepts, int $artifactCount, string $generatedAt): void
    {
        $this->writer->write($outputPath, $concepts, [
            'generated_at' => $generatedAt !== '' ? $generatedAt : null,
            'artifact_count' => $artifactCount,
        ]);
    }
}
