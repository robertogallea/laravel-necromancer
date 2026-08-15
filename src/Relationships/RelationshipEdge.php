<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * One structured relationship an artifact declares to another artifact,
 * identified by class/controller/ability string rather than a resolved
 * link — resolving a target string to an actual concept/node is each
 * consumer's own concern (Markdown link vs. graph edge).
 */
final readonly class RelationshipEdge
{
    /**
     * @param  list<string>  $targets  one for a scalar/model relationship field, one or more for a list field
     */
    public function __construct(
        public string $label,
        public array $targets,
        public ?string $relatedType = null,
    ) {}
}
