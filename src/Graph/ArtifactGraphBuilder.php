<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;
use LaravelNecromancer\Relationships\RelationshipResolver;

/**
 * Projects a manifest into a deterministic Artifact Graph: one node per
 * collected artifact, plus one edge per Relationship (from the shared
 * RelationshipResolver, so the graph and the OKF bundle can never disagree on
 * what counts as a relationship), in the resolver's canonical order.
 * Framework-free and pure — identical input always produces an identical
 * graph.
 *
 * ArtifactConceptBuilder::identify() is reused for node identity: it is the
 * package's one per-type display-label convention (route method+URI, class
 * name, gate ability, ...) and a cheap, side-effect-free lookup.
 */
final readonly class ArtifactGraphBuilder
{
    public function __construct(
        private ArtifactConceptBuilder $identity = new ArtifactConceptBuilder,
        private RelationshipResolver $relationships = new RelationshipResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function build(array $manifest): ArtifactGraph
    {
        $artifacts = (array) ($manifest['artifacts'] ?? []);
        $nodes = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($artifacts[$type] ?? []) as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $node = $this->node($type, $artifact);

                if ($node !== null) {
                    $nodes[] = $node;
                }
            }
        }

        $edges = array_map(
            ArtifactGraphEdge::fromRelationship(...),
            $this->relationships->resolve($manifest),
        );

        return new ArtifactGraph([...$nodes, ...$this->groupAndReferenceNodes($edges)], $edges);
    }

    /**
     * `facts` reuses ArtifactConceptBuilder::EXCLUDED_FACT_KEYS itself —
     * the exact same constant LaravelNecromancer\Okf\Enrichment\
     * EnrichmentPromptBuilder already reuses for the same reason — so the
     * graph's Discovered Facts can never drift from what an Artifact
     * Concept's own body excludes.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function node(string $type, array $artifact): ?ArtifactGraphNode
    {
        $identity = $this->identity->identify($type, $artifact);

        if ($identity['id'] === '') {
            return null;
        }

        $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];
        $facts = array_diff_key($artifact, array_flip(ArtifactConceptBuilder::EXCLUDED_FACT_KEYS));

        return new ArtifactGraphNode($identity['id'], $type, $identity['title'], $annotations, $facts);
    }

    /**
     * A grouping or reference edge targets a domain/flow/ADR value, not
     * another collected artifact — nothing else in the graph would
     * otherwise carry that id, so the edge would have no node to actually
     * draw a line to. This synthesizes exactly one node per distinct
     * value referenced by the already-built edges (an artifact of its
     * own, in the same spirit as LaravelNecromancer\Okf\
     * GroupConceptBuilder/AdrConceptBuilder synthesizing a Concept with
     * no artifact behind it), deduplicated and appended after every real
     * artifact node so existing node-order assumptions are unaffected.
     * Structural edges never need this: their ends are collected
     * artifacts, or stay unresolved rather than growing a synthetic node.
     *
     * @param  list<ArtifactGraphEdge>  $edges
     * @return list<ArtifactGraphNode>
     */
    private function groupAndReferenceNodes(array $edges): array
    {
        $seen = [];
        $domainNodes = [];
        $flowNodes = [];
        $adrNodes = [];

        foreach ($edges as $edge) {
            if ($edge->kind === EdgeKind::Structural || isset($seen[$edge->to])) {
                continue;
            }

            $seen[$edge->to] = true;

            if ($edge->kind === EdgeKind::Grouping) {
                [$field, $value] = explode(':', $edge->to, 2);
                $node = new ArtifactGraphNode($edge->to, $field, $value);

                if ($field === 'domain') {
                    $domainNodes[] = $node;
                } else {
                    $flowNodes[] = $node;
                }

                continue;
            }

            $path = substr($edge->to, strlen('adr:'));
            $adrNodes[] = new ArtifactGraphNode($edge->to, 'adr', pathinfo($path, PATHINFO_FILENAME));
        }

        return [...$domainNodes, ...$flowNodes, ...$adrNodes];
    }
}
