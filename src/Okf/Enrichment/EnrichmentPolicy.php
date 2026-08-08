<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment;

use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;

/**
 * Everything BundleEnricher needs to resolve one concept's enrichment,
 * bundled so its private helpers don't have to pass eight-plus loose
 * parameters (plus by-reference counters) down two call levels. Internal
 * to BundleEnricher — enrich()'s own public signature is unchanged.
 */
final readonly class EnrichmentPolicy
{
    public function __construct(
        public ConceptEnricher $enricher,
        public EnrichmentCache $cache,
        public ?string $provider,
        public ?string $model,
        public ?float $temperature,
        public string $promptVersion,
        public string $privacyPolicy,
        public bool $refresh,
    ) {}
}
