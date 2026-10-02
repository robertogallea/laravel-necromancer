<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use LaravelNecromancer\Graph\EdgeKind;

/**
 * The canonical Relationship vocabulary. Each type has exactly one
 * direction (e.g. event → listener, never listener → event), so a fact
 * recorded on both ends of a pair still yields a single Relationship.
 */
enum RelationshipType: string
{
    case HandledBy = 'handled_by';
    case UsesMiddleware = 'uses_middleware';
    case ValidatesWith = 'validates_with';
    case AuthorizedBy = 'authorized_by';
    case RelatesTo = 'relates_to';
    case ObservedBy = 'observed_by';
    case ListenedBy = 'listened_by';
    case OperatesOn = 'operates_on';
    case Dispatches = 'dispatches';
    case ResolvedAs = 'resolved_as';
    case RegisteredBy = 'registered_by';
    case TestedBy = 'tested_by';
    case BelongsToDomain = 'belongs_to_domain';
    case BelongsToFlow = 'belongs_to_flow';
    case ReferencesAdr = 'references_adr';

    /**
     * The Artifact Graph edge kind this type renders as, which drives
     * graph.html's line styling and per-kind toggles.
     */
    public function kind(): EdgeKind
    {
        return match ($this) {
            self::BelongsToDomain, self::BelongsToFlow => EdgeKind::Grouping,
            self::ReferencesAdr => EdgeKind::Reference,
            self::Dispatches => EdgeKind::Behavioral,
            default => EdgeKind::Structural,
        };
    }
}
