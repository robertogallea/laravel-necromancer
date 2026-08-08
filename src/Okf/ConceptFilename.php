<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

use Illuminate\Support\Str;

/**
 * Deterministic filename for any concept in the bundle: a readable slug of
 * its title plus a short hash of its canonical id. The slug is for
 * browsability only — the id inside the concept's own front matter is what
 * identifies it, so a collision on slug alone is harmless.
 */
final class ConceptFilename
{
    public static function make(string $title, string $id): string
    {
        // Str::slug() drops backslashes rather than treating them as word
        // boundaries, so a class-derived title would collapse into one
        // unreadable run (e.g. "appjobssendinvoice"); replacing them with
        // spaces first keeps namespace segments distinct in the slug.
        $slug = Str::slug(str_replace('\\', ' ', $title));
        $hash = substr(hash('sha256', $id), 0, 8);

        return ($slug !== '' ? "{$slug}-" : '')."{$hash}.md";
    }
}
