<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use JsonSerializable;

/**
 * The deterministic node/edge projection of a manifest: canonically-sorted
 * nodes, one per collected artifact, plus their structural, grouping
 * (domain/flow), and reference (ADR) relationships as edges. Edge kinds
 * land in a later iteration (issue #21) — this value object already
 * carries the `edges` field so graph.json's shape doesn't change shape out
 * from under `necromancer:graph`'s HTML viewer once they do.
 */
final readonly class ArtifactGraph implements JsonSerializable
{
    /**
     * @param  list<ArtifactGraphNode>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges = [],
    ) {}

    /**
     * @return array{nodes: list<ArtifactGraphNode>, edges: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'nodes' => $this->nodes,
            'edges' => $this->edges,
        ];
    }
}
