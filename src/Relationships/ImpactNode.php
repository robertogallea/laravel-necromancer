<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use JsonSerializable;
use stdClass;

/**
 * One node reached while computing an Impact, at its shortest distance
 * from the start. `via` is the Relationship that first reached it from
 * `viaFrom`. `type` is the artifact type, `domain`/`flow`/`adr` for a
 * synthesized concept, or null for an unresolved end — the raw class or
 * name Necromancer did not collect, reported once however many
 * Relationships end at it.
 */
final readonly class ImpactNode implements JsonSerializable
{
    public function __construct(
        public string $id,
        public ?string $type,
        public int $distance,
        public Relationship $via,
        public string $viaFrom,
        public ImpactDirection $direction,
    ) {}

    public function resolved(): bool
    {
        return $this->type !== null;
    }

    /**
     * Empty Relationship metadata serializes as a JSON object, matching
     * graph.json's edges.
     *
     * @return array{id: string, type: ?string, distance: int, resolved: bool, via: array{from: string, relationship: array<string, mixed>, direction: string}}
     */
    public function jsonSerialize(): array
    {
        $relationship = $this->via->jsonSerialize();

        if ($relationship['metadata'] === []) {
            $relationship['metadata'] = new stdClass;
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'distance' => $this->distance,
            'resolved' => $this->resolved(),
            'via' => [
                'from' => $this->viaFrom,
                'relationship' => $relationship,
                'direction' => $this->direction->value,
            ],
        ];
    }
}
