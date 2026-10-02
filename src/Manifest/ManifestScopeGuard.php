<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

/**
 * The stale/partial-manifest refusal shared by every command that projects
 * the manifest without rescanning (necromancer:okf, necromancer:graph,
 * necromancer:impact), so they all refuse the same unsafe input with the
 * same wording. Staleness is computed by the caller (it needs the
 * container's basePath()), keeping this class framework-free.
 */
final class ManifestScopeGuard
{
    /**
     * Returns the refusal message, or null when the manifest may be used.
     * $proceed completes "pass --allow-stale to …" (e.g. "export anyway").
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function refusal(array $manifest, bool $stale, bool $allowStale, bool $allowPartial, string $proceed): ?string
    {
        if ($stale && ! $allowStale) {
            return "Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to {$proceed}.";
        }

        $scope = is_array($manifest['meta']['scope'] ?? null) ? $manifest['meta']['scope'] : [];
        $complete = (bool) ($scope['complete'] ?? false);

        if (! $complete && ! $allowPartial) {
            return "Manifest scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to {$proceed}.";
        }

        return null;
    }
}
