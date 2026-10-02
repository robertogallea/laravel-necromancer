<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * Which end of the reaching Relationship an Impact node was reached from:
 * `Out` when the node it was reached from is the Relationship's `from`,
 * `In` when it is its `to`.
 */
enum ImpactDirection: string
{
    case Out = 'out';
    case In = 'in';
}
