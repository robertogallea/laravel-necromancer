<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * AI-generated prose for one concept, plus enough provenance for a reader
 * to tell generated content apart from the deterministic bundle it sits
 * alongside. Passing an instance into a concept builder only ever adds a
 * new `enrichment` front-matter block and body section — it carries no
 * facts, annotations, id, or link data, so it cannot alter any of those by
 * construction.
 */
final readonly class ConceptEnrichment
{
    /**
     * $provider and $model are null when the caller left the AI provider's
     * own default in effect — recording the literal string "default" there
     * would misrepresent it as a provider/model actually named that, so
     * toFrontMatter() omits the field entirely instead.
     */
    public function __construct(
        public string $description,
        public string $narrative,
        public ?string $provider,
        public ?string $model,
        public string $promptVersion,
        public string $privacyPolicy,
        public string $cacheKey,
        public bool $cached,
    ) {}

    /**
     * The single source of truth for how enrichment provenance is
     * serialized under `necromancer.enrichment` — shared by every concept
     * builder so the three families can never drift on field names.
     *
     * @return array{provider?: string, model?: string, prompt_version: string, privacy_policy: string, cache_key: string, cached: bool}
     */
    public function toFrontMatter(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'privacy_policy' => $this->privacyPolicy,
            'cache_key' => $this->cacheKey,
            'cached' => $this->cached,
        ], fn (mixed $value): bool => $value !== null);
    }
}
