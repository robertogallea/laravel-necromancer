<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

use LaravelNecromancer\Okf\ArtifactConcept;
use LaravelNecromancer\Okf\AtomicBundleWriter;
use LaravelNecromancer\Okf\BundleExporter;
use LaravelNecromancer\Okf\ConceptEnrichment;
use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;
use LaravelNecromancer\Okf\ManifestContentHash;
use RuntimeException;
use Throwable;

/**
 * Builds an enriched sibling Knowledge Bundle: the same deterministic
 * concepts necromancer:okf would produce, each optionally carrying AI
 * generated prose. Never writes to the primary bundle's output path, and
 * never rescans the application — the manifest passed in is the sole input,
 * exactly like BundleExporter.
 *
 * Concept building itself is delegated entirely to BundleExporter::assemble()
 * — this class never calls a concept builder directly, so facts,
 * annotations, Artifact IDs, and links can never diverge from what the
 * deterministic exporter would produce for the same manifest.
 */
final class BundleEnricher
{
    public function __construct(
        private readonly BundleExporter $exporter = new BundleExporter,
        private readonly EnrichmentPromptBuilder $promptBuilder = new EnrichmentPromptBuilder,
        private readonly AtomicBundleWriter $writer = new AtomicBundleWriter,
        private readonly EnrichedBundleReadmeBuilder $readmeBuilder = new EnrichedBundleReadmeBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function enrich(
        array $manifest,
        ConceptEnricher $enricher,
        EnrichmentCache $cache,
        string $outputPath,
        string $basePath,
        bool $stale,
        bool $allowStale,
        bool $allowPartial,
        string $privacyPolicy,
        string $promptVersion,
        ?string $provider,
        ?string $model,
        ?float $temperature,
        bool $refresh = false,
    ): BundleEnrichmentResult {
        $scopeError = $this->exporter->validateScope($manifest, $stale, $allowStale, $allowPartial);

        if ($scopeError !== null) {
            return BundleEnrichmentResult::failure($scopeError);
        }

        $policy = new EnrichmentPolicy($enricher, $cache, $provider, $model, $temperature, $promptVersion, $privacyPolicy, $refresh);
        $generatedAt = (string) ($manifest['meta']['generated_at'] ?? '');
        $contentHash = ManifestContentHash::resolve($manifest);

        // Two calls to assemble() against the same $manifest/$basePath: the
        // first (no enrichments) only discovers which concepts exist and
        // what to build a prompt from, reusing BundleExporter's own
        // indexing/grouping logic instead of duplicating it; the second
        // attaches the resolved enrichments for the actual output. Both
        // calls are pure functions of $manifest, so they always agree.
        try {
            $discovery = $this->exporter->assemble($manifest, $generatedAt, $basePath);
        } catch (RuntimeException $e) {
            return BundleEnrichmentResult::failure($e->getMessage());
        }

        $enrichments = $this->resolveAllEnrichments($manifest, $discovery, $policy);

        try {
            $final = $this->exporter->assemble($manifest, $generatedAt, $basePath, $enrichments);
        } catch (RuntimeException $e) {
            return BundleEnrichmentResult::failure($e->getMessage());
        }

        $concepts = [...$final['artifact'], ...$final['group'], ...$final['adr']];
        $cachedCount = count(array_filter($enrichments, fn (ConceptEnrichment $e): bool => $e->cached));
        $freshCount = count($enrichments) - $cachedCount;

        try {
            $this->writer->write(
                $outputPath,
                $concepts,
                [
                    'generated_at' => $generatedAt !== '' ? $generatedAt : null,
                    'concept_count' => count($concepts),
                    'cached_count' => $cachedCount,
                    'fresh_count' => $freshCount,
                    'content_hash' => $contentHash,
                ],
                $this->readmeBuilder->build($generatedAt, count($concepts), $freshCount, $cachedCount),
            );
        } catch (Throwable $e) {
            return BundleEnrichmentResult::failure("Failed to write the enriched bundle: {$e->getMessage()}");
        }

        return BundleEnrichmentResult::success($outputPath, count($concepts), $cachedCount, $freshCount);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{artifact: list<ArtifactConcept>, group: list<ArtifactConcept>, adr: list<ArtifactConcept>, identities: array<string, array{type: string, link: mixed, annotations: array<string, mixed>}>}  $discovery
     * @return array<string, ConceptEnrichment>
     */
    private function resolveAllEnrichments(array $manifest, array $discovery, EnrichmentPolicy $policy): array
    {
        $enrichments = [];

        foreach ((array) ($manifest['artifacts'] ?? []) as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            foreach ($items as $artifact) {
                if (! is_array($artifact) || (string) ($artifact['id'] ?? '') === '') {
                    continue;
                }

                $id = (string) $artifact['id'];
                $prompt = $this->promptBuilder->forArtifact($type, $artifact);
                $enrichments[$id] = $this->resolveOne($id, $prompt, $policy);
            }
        }

        foreach ($discovery['group'] as $concept) {
            [$kind, $value] = explode(':', $concept->id, 2);
            $memberIds = array_keys(array_filter(
                $discovery['identities'],
                fn (array $meta): bool => ($meta['annotations'][$kind] ?? null) === $value,
            ));

            $prompt = $this->promptBuilder->forGroup($kind, $value, $memberIds);
            $enrichments[$concept->id] = $this->resolveOne($concept->id, $prompt, $policy);
        }

        foreach ($discovery['adr'] as $concept) {
            $path = substr($concept->id, strlen('adr:'));
            $referencedByIds = array_keys(array_filter(
                $discovery['identities'],
                fn (array $meta): bool => in_array($path, (array) ($meta['annotations']['adrs'] ?? []), true),
            ));

            $prompt = $this->promptBuilder->forAdr($path, $referencedByIds);
            $enrichments[$concept->id] = $this->resolveOne($concept->id, $prompt, $policy);
        }

        return $enrichments;
    }

    private function resolveOne(string $id, string $prompt, EnrichmentPolicy $policy): ConceptEnrichment
    {
        $cacheKey = $this->cacheKey($prompt, $policy);
        $cached = ! $policy->refresh && $policy->cache->has($id, $cacheKey);

        if ($cached) {
            $raw = $policy->cache->get($id, $cacheKey);
        } else {
            $raw = $policy->enricher->enrich($prompt, $policy->provider, $policy->model, $policy->temperature);
            $policy->cache->set($id, $cacheKey, $raw);
        }

        return new ConceptEnrichment(
            description: $raw->description,
            narrative: $raw->narrative,
            provider: $policy->provider,
            model: $policy->model,
            promptVersion: $policy->promptVersion,
            privacyPolicy: $policy->privacyPolicy,
            cacheKey: $cacheKey,
            cached: $cached,
        );
    }

    private function cacheKey(string $prompt, EnrichmentPolicy $policy): string
    {
        return implode(':', [
            hash('sha256', $prompt),
            $policy->provider ?? 'default',
            $policy->model ?? 'default',
            $policy->temperature !== null ? (string) $policy->temperature : 'default',
            $policy->promptVersion,
        ]);
    }
}
