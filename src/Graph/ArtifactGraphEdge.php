<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use JsonSerializable;

/**
 * One relationship between two artifacts in the Artifact Graph: a
 * structural link (route→controller, model→relationships/policy/observers,
 * event→listeners, listener→handles, policy→model, observer→model), a
 * grouping link (an artifact declaring `domain`/`flow` to its group), or a
 * reference link (an artifact declaring `adrs` to a locally declared ADR).
 *
 * `to` is the resolved canonical id of the target — either a collected
 * artifact's own id, or the id of a synthesized domain/flow/ADR node
 * (ArtifactGraphBuilder::groupAndReferenceNodes()) for a grouping or
 * reference edge — so the edge always has a real node to draw a line to.
 * The one exception is a route's `controller`: no artifact type
 * represents controllers and no synthetic node is grown for them either,
 * so that edge's `to` stays the raw controller class string, mirroring
 * LaravelNecromancer\Okf\ArtifactConceptBuilder's "link when resolvable,
 * plain text otherwise" convention for the same case.
 */
final readonly class ArtifactGraphEdge implements JsonSerializable
{
    public function __construct(
        public string $from,
        public string $to,
        public EdgeKind $kind,
    ) {}

    /**
     * @return array{from: string, to: string, kind: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'kind' => $this->kind->value,
        ];
    }
}
