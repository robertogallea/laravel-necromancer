<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;
use LaravelNecromancer\Okf\UriReference;
use LaravelNecromancer\Relationships\RelationshipEdge;
use LaravelNecromancer\Relationships\RelationshipResolver;

/**
 * Projects a manifest into a deterministic Artifact Graph: one node per
 * collected artifact plus its structural, grouping (domain/flow), and
 * reference (ADR) relationships as edges — canonically ordered throughout.
 * Framework-free and pure — mirrors LaravelNecromancer\Okf\BundleExporter's
 * shape so the manifest is the sole input and identical input always
 * produces an identical graph.
 *
 * Per ADR-0001 ("Artifact Graph is manifest-native, not an OKF feature"),
 * necromancer:graph never requires necromancer:okf to have run — but that
 * ADR explicitly sanctions reusing OKF's internal indexing logic as a
 * private implementation detail. ArtifactConceptBuilder::identify() is
 * reused here for exactly that reason: it is already the package's one
 * per-type display-label convention (route method+URI, class name, gate
 * ability, ...), and BundleExporter itself already treats it as a cheap,
 * side-effect-free identity lookup rather than an OKF-specific operation.
 * Structural edges reuse the same taxonomy (LaravelNecromancer\
 * Relationships\RelationshipResolver, extracted in issue #19 specifically
 * so the OKF bundle and the Artifact Graph can never disagree on what
 * counts as a relationship) rather than re-deriving it.
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
        $classIndex = $this->buildClassIndex($artifacts);
        $nodes = [];
        $edges = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($artifacts[$type] ?? []) as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $node = $this->node($type, $artifact);

                if ($node === null) {
                    continue;
                }

                $nodes[] = $node;
                $edges = [...$edges, ...$this->edgesFor($type, $node->id, $artifact, $classIndex)];
            }
        }

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
     * A class name → canonical Artifact ID map, so a relationship field
     * that names another artifact by class (e.g. a model's `policy`) can
     * resolve to that artifact's own id. Unlike ArtifactConceptBuilder's
     * equivalent classIndex, routes are never a source here — a route has
     * no `class` field, only `controller`, and no artifact type represents
     * controllers, so a route→controller edge's `to` is always the raw
     * controller class string rather than resolved to a sibling route.
     * Middleware is excluded for the same reason BundleExporter excludes
     * it: one class can register globally, in a group, and under an
     * alias, so a bare class name can't resolve to one specific
     * registration unambiguously.
     *
     * @param  array<string, mixed>  $artifacts
     * @return array<string, string>
     */
    private function buildClassIndex(array $artifacts): array
    {
        $index = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            if ($type === 'middleware' || $type === 'routes') {
                continue;
            }

            foreach ((array) ($artifacts[$type] ?? []) as $artifact) {
                if (! is_array($artifact)) {
                    continue;
                }

                $id = (string) ($artifact['id'] ?? '');
                $class = $artifact['class'] ?? null;

                if ($id === '' || ! is_string($class) || $class === '' || isset($index[$class])) {
                    continue;
                }

                $index[$class] = $id;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, string>  $classIndex
     * @return list<ArtifactGraphEdge>
     */
    private function edgesFor(string $type, string $fromId, array $artifact, array $classIndex): array
    {
        $edges = [];

        foreach ($this->relationships->resolve($type, $artifact) as $relationship) {
            $edges = [...$edges, ...$this->structuralEdges($fromId, $relationship, $classIndex)];
        }

        $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];

        foreach (['domain', 'flow'] as $field) {
            $value = $annotations[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $edges[] = new ArtifactGraphEdge($fromId, "{$field}:{$value}", EdgeKind::Grouping);
            }
        }

        foreach ((array) ($annotations['adrs'] ?? []) as $adr) {
            if (is_string($adr) && $adr !== '' && ! UriReference::isAbsolute($adr)) {
                $edges[] = new ArtifactGraphEdge($fromId, "adr:{$adr}", EdgeKind::Reference);
            }
        }

        return $edges;
    }

    /**
     * @param  array<string, string>  $classIndex
     * @return list<ArtifactGraphEdge>
     */
    private function structuralEdges(string $fromId, RelationshipEdge $relationship, array $classIndex): array
    {
        return array_map(
            fn (string $target): ArtifactGraphEdge => new ArtifactGraphEdge($fromId, $classIndex[$target] ?? $target, EdgeKind::Structural),
            $relationship->targets,
        );
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
     * Structural edges never need this: their targets already resolve to
     * a real node whenever the target type is itself collected, and a
     * route's controller — the one structural target no artifact type
     * represents — is deliberately left unresolved rather than growing a
     * synthetic "controller" node kind with no annotation-backed identity
     * of its own.
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
