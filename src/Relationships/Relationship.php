<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use JsonSerializable;

/**
 * A directed, typed link from an artifact to another artifact, a Domain, a
 * Flow, or an ADR, derived from Discovered Facts and Artifact Annotations
 * (never stored in the manifest — see docs/adr/0019).
 *
 * `from`/`to` are Artifact IDs (or `domain:<v>`/`flow:<v>`/`adr:<path>`).
 * An end that names something Necromancer did not collect keeps the raw
 * class or name instead, and the Relationship is marked unresolved.
 */
final readonly class Relationship implements JsonSerializable
{
    /**
     * @param  list<Provenance>  $provenance
     * @param  array<string, mixed>  $metadata
     * @param  list<RelationshipEvidence>  $evidence
     */
    public function __construct(
        public string $from,
        public RelationshipType $type,
        public string $to,
        public array $provenance,
        public bool $resolved,
        public array $metadata = [],
        public array $evidence = [],
    ) {}

    /**
     * @return array{from: string, type: string, to: string, provenance: list<string>, resolved: bool, metadata: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'type' => $this->type->value,
            'to' => $this->to,
            'provenance' => array_map(fn (Provenance $provenance): string => $provenance->value, $this->provenance),
            'resolved' => $this->resolved,
            'metadata' => $this->metadata,
        ];
    }
}
