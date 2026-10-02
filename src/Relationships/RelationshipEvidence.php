<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * One manifest fact supporting a Relationship: the artifact holding it, the
 * fact's field, the raw value it names, and that value's position within the
 * field (0 for a scalar field). Kept in memory only (never
 * serialized), so a consumer can render exactly the facts an artifact itself
 * holds — the OKF bundle relies on this to show `policy` on a model only when
 * the model declares it, even though the Relationship also has evidence from
 * the policy's side.
 */
final readonly class RelationshipEvidence
{
    public function __construct(
        public string $artifact,
        public string $field,
        public string $value,
        public int $position = 0,
    ) {}
}
