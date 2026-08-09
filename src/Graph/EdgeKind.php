<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

/**
 * What kind of relationship an ArtifactGraphEdge represents — used both in
 * graph.json's schema and for distinct line styling per kind in graph.html.
 */
enum EdgeKind: string
{
    case Structural = 'structural';
    case Grouping = 'grouping';
    case Reference = 'reference';
}
