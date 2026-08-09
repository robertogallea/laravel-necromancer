<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;

/**
 * Projects a manifest into a deterministic Artifact Graph: one node per
 * collected artifact, canonically ordered. Framework-free and pure —
 * mirrors LaravelNecromancer\Okf\BundleExporter's shape so the manifest is
 * the sole input and identical input always produces an identical graph.
 *
 * Per ADR-0001 ("Artifact Graph is manifest-native, not an OKF feature"),
 * necromancer:graph never requires necromancer:okf to have run — but that
 * ADR explicitly sanctions reusing OKF's internal indexing logic as a
 * private implementation detail. ArtifactConceptBuilder::identify() is
 * reused here for exactly that reason: it is already the package's one
 * per-type display-label convention (route method+URI, class name, gate
 * ability, ...), and BundleExporter itself already treats it as a cheap,
 * side-effect-free identity lookup rather than an OKF-specific operation.
 */
final readonly class ArtifactGraphBuilder
{
    public function __construct(
        private ArtifactConceptBuilder $identity = new ArtifactConceptBuilder,
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

        return new ArtifactGraph($nodes);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function node(string $type, array $artifact): ?ArtifactGraphNode
    {
        $identity = $this->identity->identify($type, $artifact);

        if ($identity['id'] === '') {
            return null;
        }

        $annotations = is_array($artifact['annotations'] ?? null) ? $artifact['annotations'] : [];

        return new ArtifactGraphNode($identity['id'], $type, $identity['title'], $annotations);
    }
}
