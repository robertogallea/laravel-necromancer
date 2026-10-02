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
     */
    public function __construct(
        public string $start,
        public int $depth,
        public array $nodes,
    ) {}
}
