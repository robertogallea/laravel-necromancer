<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * How Necromancer obtained a piece of evidence for a Relationship: from the
 * application's runtime state, by reflecting on declared code structure, by
 * reading source text, or from an Artifact Annotation.
 */
enum Provenance: string
{
    case Runtime = 'runtime';
    case Reflection = 'reflection';
    case Source = 'source';
    case Annotation = 'annotation';
}
