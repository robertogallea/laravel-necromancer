<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf\Enrichment\Contracts;

use LaravelNecromancer\Okf\Enrichment\RawEnrichment;

interface ConceptEnricher
{
    public function enrich(string $prompt, ?string $provider = null, ?string $model = null, ?float $temperature = null): RawEnrichment;
}
