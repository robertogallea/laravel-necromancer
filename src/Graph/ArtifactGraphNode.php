<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use JsonSerializable;

/**
 * One node in the Artifact Graph: a single collected artifact, carrying its
 * canonical Artifact ID, artifact type (the primary color dimension in the
 * viewer), a human-readable display label, its resolved Artifact
 * Annotations when it declares any, and its Discovered Facts — every
 * artifact key not in LaravelNecromancer\Okf\ArtifactConceptBuilder::
 * EXCLUDED_FACT_KEYS, the same raw shape that builder's own `facts` front
 * matter carries (not the display-filtered Markdown rendering). A
 * synthetic domain/flow/adr node (ArtifactGraphBuilder::
 * groupAndReferenceNodes()) has no backing manifest artifact, so its
 * `facts` is always empty, same as its `annotations`.
 */
final readonly class ArtifactGraphNode implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $annotations
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public array $annotations = [],
        public array $facts = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,
            'annotations' => $this->annotations,
            'facts' => $this->facts,
        ], fn (mixed $value, string $key): bool => ! in_array($key, ['annotations', 'facts'], true) || $value !== [], ARRAY_FILTER_USE_BOTH);
    }
}
