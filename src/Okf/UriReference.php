<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * Distinguishes an absolute URI (external, left as a plain link) from a
 * bundle-local path (resolved and, for ADRs, copied into the bundle).
 * Mirrors the same scheme-prefix check LaravelNecromancer\Audit\Checks\
 * MissingLocalAdrFileCheck uses for the equivalent audit finding.
 */
final class UriReference
{
    public static function isAbsolute(string $value): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value);
    }
}
