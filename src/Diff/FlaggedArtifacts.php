<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

use LaravelNecromancer\Collection\RouteMetadataNormalizer;

final readonly class FlaggedArtifacts
{
    /**
     * @return list<array{type: string, artifact: array<string, mixed>}>
     */
    public static function fromDiff(ManifestDiff $diff): array
    {
        $flagged = [];

        $types = array_unique([...array_keys($diff->added), ...array_keys($diff->changed)]);

        foreach ($types as $type) {
            $added = $diff->added[$type] ?? [];
            $changed = array_map(fn (array $change): array => $change['to'], $diff->changed[$type] ?? []);

            foreach ([...$added, ...$changed] as $artifact) {
                if (self::isFlagged($artifact)) {
                    $flagged[] = ['type' => $type, 'artifact' => $artifact];
                }
            }
        }

        return $flagged;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    public static function isFlagged(array $artifact): bool
    {
        $annotations = $artifact['annotations'] ?? [];

        return in_array($annotations['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true)
            || ! empty($annotations['external_services']);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    public static function reason(array $artifact): string
    {
        $annotations = $artifact['annotations'] ?? [];
        $parts = [];

        foreach (['domain', 'flow', 'capability'] as $field) {
            if (! empty($annotations[$field] ?? null)) {
                $parts[] = "{$field}: {$annotations[$field]}";
            }
        }

        if (in_array($annotations['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true)) {
            $parts[] = 'risk: '.$annotations['risk'];
        }

        if (! empty($annotations['external_services'])) {
            $parts[] = 'external services: '.implode(', ', $annotations['external_services']);
        }

        return implode(' · ', $parts);
    }
}
