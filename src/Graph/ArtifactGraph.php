<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use JsonSerializable;

/**
 * The deterministic node/edge projection of a manifest: canonically-sorted
 * nodes, one per collected artifact, plus their structural, grouping
 * (domain/flow), and reference (ADR) relationships as edges.
 */
final readonly class ArtifactGraph implements JsonSerializable
{
    /**
     * @param  list<ArtifactGraphNode>  $nodes
     * @param  list<ArtifactGraphEdge>  $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges = [],
    ) {}

    /**
     * @return array{nodes: list<ArtifactGraphNode>, edges: list<ArtifactGraphEdge>}
     */
    public function jsonSerialize(): array
    {
        return [
            'nodes' => $this->nodes,
            'edges' => $this->edges,
        ];
    }
}
