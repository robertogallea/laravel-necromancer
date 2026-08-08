<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

/**
 * One AI call's raw output for one concept — description, narrative, and
 * token usage. Distinct from LaravelNecromancer\Okf\ConceptEnrichment,
 * which additionally carries the provenance (provider/model/cache) that
 * BundleEnricher attaches once it knows whether this result came from
 * cache or a fresh call.
 */
final readonly class RawEnrichment
{
    public function __construct(
        public string $description,
        public string $narrative,
        public int $promptTokens,
        public int $completionTokens,
    ) {}
}
