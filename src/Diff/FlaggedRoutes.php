<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

use LaravelNecromancer\Collection\RouteMetadataNormalizer;

final readonly class FlaggedRoutes
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function fromDiff(ManifestDiff $diff): array
    {
        $added = $diff->added['routes'] ?? [];
        $changed = array_map(fn (array $change): array => $change['to'], $diff->changed['routes'] ?? []);

        return array_values(array_filter(
            [...$added, ...$changed],
            fn (array $route): bool => self::isFlagged($route),
        ));
    }

    /**
     * @param  array<string, mixed>  $route
     */
    public static function isFlagged(array $route): bool
    {
        $necromancer = $route['route_metadata']['necromancer'] ?? [];

        return in_array($necromancer['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true)
            || ! empty($necromancer['external_services']);
    }

    /**
     * @param  array<string, mixed>  $route
     */
    public static function reason(array $route): string
    {
        $necromancer = $route['route_metadata']['necromancer'] ?? [];
        $parts = [];

        foreach (['domain', 'flow', 'capability'] as $field) {
            if (! empty($necromancer[$field] ?? null)) {
                $parts[] = "{$field}: {$necromancer[$field]}";
            }
        }

        if (in_array($necromancer['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true)) {
            $parts[] = 'risk: '.$necromancer['risk'];
        }

        if (! empty($necromancer['external_services'])) {
            $parts[] = 'external services: '.implode(', ', $necromancer['external_services']);
        }

        return implode(' · ', $parts);
    }
}
