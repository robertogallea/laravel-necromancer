<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * The nodes reachable from a starting artifact within a depth (see
 * CONTEXT.md, **Impact**), sorted by distance then by the canonical order
 * of the Relationship that reached each one. The start is not listed.
 */
final readonly class Impact
{
    /**
     * @param  list<ImpactNode>  $nodes
     * @param  array<string, string>  $labels  Artifact ID → display label, for every collected artifact
     */
    public function __construct(
        public string $start,
        public int $depth,
        public array $nodes,
        private array $labels = [],
    ) {}

    /**
     * The nodes of the given types, in order; every node when $types is
     * empty. A display filter only: unresolved nodes have no type, so any
     * filter hides them.
     *
     * @param  list<string>  $types
     * @return list<ImpactNode>
     */
    public function nodesOfTypes(array $types): array
    {
        if ($types === []) {
            return $this->nodes;
        }

        return array_values(array_filter(
            $this->nodes,
            fn (ImpactNode $node): bool => in_array($node->type, $types, true),
        ));
    }

    /**
     * The display label of the start or a reached node. Domain/Flow/ADR
     * ids and unresolved ends are their own label.
     */
    public function label(string $id): string
    {
        $label = $this->labels[$id] ?? '';

        return $label !== '' ? $label : $id;
    }
}
