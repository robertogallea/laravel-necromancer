<?php

declare(strict_types=1);

namespace LaravelNecromancer\Okf;

/**
 * Resolves the manifest's own `meta.content_hash` for recording into a
 * bundle.json index — `null` when absent or empty, never a computed
 * fallback. Shared by BundleExporter and BundleEnricher\BundleEnricher so
 * the two bundle writers can't drift on what counts as "no content hash."
 *
 * This is a distinct concept from the content-hash handling in
 * InferCommand, which falls back to computing a fresh sha256 when the
 * manifest carries none — that command needs a cache key regardless,
 * whereas a bundle's content_hash is only ever a copy of what the
 * manifest already declared.
 */
final class ManifestContentHash
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function resolve(array $manifest): ?string
    {
        $contentHash = $manifest['meta']['content_hash'] ?? null;

        return is_string($contentHash) && $contentHash !== '' ? $contentHash : null;
    }
}
