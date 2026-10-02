<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use JsonSerializable;

/**
 * A test reached by the Impact of a changed artifact, or a changed test
 * itself (see CONTEXT.md, **Affected Test**). A changed test has distance
 * 0 and no reaching node; any other test carries the ImpactNode that
 * reached it from `start` and the display label of the node it was
 * reached from.
 */
final readonly class AffectedTest implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $file,
        public int $distance,
        public string $start,
        public ?ImpactNode $node = null,
        public ?string $viaLabel = null,
    ) {}

    /**
     * Distance 1 tests the changed artifact; distance 0 is the changed
     * test itself.
     */
    public function directlyAffected(): bool
    {
        return $this->distance <= 1;
    }

    /**
     * The reaching tested_by Relationship's `exact`/`namespace`/`reference`
     * match, or null for a changed test.
     */
    public function match(): ?string
    {
        $match = $this->node?->via->metadata['match'] ?? null;

        return is_string($match) ? $match : null;
    }

    /**
     * @return array{file: string, id: string, distance: int, match: ?string, start: string, via: array{from: string, relationship: array<string, mixed>, direction: string}|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'file' => $this->file,
            'id' => $this->id,
            'distance' => $this->distance,
            'match' => $this->match(),
            'start' => $this->start,
            'via' => $this->node?->jsonSerialize()['via'],
        ];
    }
}
