<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use JsonSerializable;

/**
 * One node in the Artifact Graph: a single collected artifact, carrying its
 * canonical Artifact ID, artifact type (the primary color dimension in the
 * viewer), a human-readable display label, and its resolved Artifact
 * Annotations when it declares any.
 */
final readonly class ArtifactGraphNode implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $annotations
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public array $annotations = [],
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
        ], fn (mixed $value, string $key): bool => $key !== 'annotations' || $value !== [], ARRAY_FILTER_USE_BOTH);
    }
}
