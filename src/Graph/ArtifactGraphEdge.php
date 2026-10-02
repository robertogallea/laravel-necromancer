<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use JsonSerializable;
use LaravelNecromancer\Relationships\Provenance;
use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipType;
use stdClass;

/**
 * The Artifact Graph rendering of one Relationship. `kind` (structural,
 * grouping, reference) is derived from the relationship type and drives
 * graph.html's line styling and per-kind toggles.
 *
 * `from`/`to` are canonical ids — a collected artifact's own id, or a
 * synthesized domain/flow/ADR node's id — except for an end Necromancer did
 * not collect (e.g. a vendor controller), which keeps its raw class string
 * and leaves the edge `resolved: false`; graph.html simply doesn't draw it.
 */
final readonly class ArtifactGraphEdge implements JsonSerializable
{
    public EdgeKind $kind;

    /**
     * @param  list<Provenance>  $provenance
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $from,
        public string $to,
        public RelationshipType $type,
        public array $provenance,
        public bool $resolved,
        public array $metadata = [],
    ) {
        $this->kind = $type->kind();
    }

    public static function fromRelationship(Relationship $relationship): self
    {
        return new self(
            $relationship->from,
            $relationship->to,
            $relationship->type,
            $relationship->provenance,
            $relationship->resolved,
            $relationship->metadata,
        );
    }

    /**
     * Empty metadata serializes as a JSON object, so every edge's
     * `metadata` has the same JSON type.
     *
     * @return array{from: string, to: string, type: string, kind: string, provenance: list<string>, resolved: bool, metadata: array<string, mixed>|stdClass}
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'type' => $this->type->value,
            'kind' => $this->kind->value,
            'provenance' => array_map(fn (Provenance $provenance): string => $provenance->value, $this->provenance),
            'resolved' => $this->resolved,
            'metadata' => $this->metadata === [] ? new stdClass : $this->metadata,
        ];
    }
}
