<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

/**
 * How a test is linked to an artifact by a tested_by Relationship: its
 * subject names the artifact's class (`exact`) or a namespace above it
 * (`namespace`), or its source references the class or route name
 * (`reference`).
 */
enum TestMatch: string
{
    case Exact = 'exact';
    case Namespace = 'namespace';
    case Reference = 'reference';

    /**
     * The qualifier for a raw `match` metadata value; empty when it is
     * missing or unknown.
     */
    public static function qualifierFor(mixed $value): string
    {
        return is_string($value) ? (self::tryFrom($value)?->qualifier() ?? '') : '';
    }

    /**
     * The label shown next to a test linked this way; empty for an exact
     * match, which needs none.
     */
    public function qualifier(): string
    {
        return match ($this) {
            self::Exact => '',
            self::Namespace => 'namespace match',
            self::Reference => 'reference',
        };
    }
}
