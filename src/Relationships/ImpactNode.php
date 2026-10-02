<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use JsonSerializable;

/**
 * One node reached while computing an Impact, at its shortest distance
 * from the start. `via` is the Relationship that first reached it from
 * `viaFrom`; `direction` is `out` when `viaFrom` is that Relationship's
 * `from`, `in` when it is its `to`. `type` is the artifact type, `domain`/
 * `flow`/`adr` for a synthesized concept, or null for an unresolved end.
 */
final readonly class ImpactNode implements JsonSerializable
{
    /**
     * @param  'out'|'in'  $direction
     */
    public function __construct(
        public string $id,
        public ?string $type,
        public int $distance,
        public bool $resolved,
        public Relationship $via,
        public string $viaFrom,
        public string $direction,
    ) {}

    /**
     * @return array{id: string, type: ?string, distance: int, resolved: bool, via: array{from: string, relationship: array<string, mixed>, direction: string}}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'distance' => $this->distance,
            'resolved' => $this->resolved,
            'via' => [
                'from' => $this->viaFrom,
                'relationship' => $this->via->jsonSerialize(),
                'direction' => $this->direction,
            ],
        ];
    }
}
