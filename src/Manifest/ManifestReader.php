<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use JsonException;
use LaravelNecromancer\Metadata\RouteAnnotationResolver;

final readonly class ManifestReader
{
    /**
     * @return array<string, mixed>
     *
     * @throws ManifestNotFoundException
     * @throws JsonException
     */
    public function read(string $path): array
    {
        if (! file_exists($path)) {
            throw new ManifestNotFoundException("Necromancer manifest not found at {$path}.");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ManifestNotFoundException("Necromancer manifest could not be read at {$path}.");
        }

        /** @var array<string, mixed> $manifest */
        $manifest = (array) json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $this->adapt($manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function adapt(array $manifest): array
    {
        if (($manifest['meta']['manifest_schema_version'] ?? null) === 1) {
            return $manifest;
        }

        return $this->adaptLegacyManifest($manifest);
    }

    /**
     * Promote a pre-schema manifest into the shape expected by 1.x consumers
     * without writing it back to disk. Its scope remains deliberately unknown:
     * older manifests did not record whether they came from a partial scan.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function adaptLegacyManifest(array $manifest): array
    {
        $artifacts = is_array($manifest['artifacts'] ?? null) ? $manifest['artifacts'] : [];
        $typedArtifacts = [];
        $unsupportedArtifacts = [];
        $artifactId = new ArtifactId;

        foreach ($artifacts as $type => $items) {
            if (! is_string($type) || ! is_array($items)) {
                continue;
            }

            $items = array_map(static fn (mixed $item): array => (array) $item, $items);

            if ($type === 'routes') {
                $items = array_map(fn (array $route): array => $this->promoteLegacyRoute($route), $items);
            }

            if ($artifactId->supports($type)) {
                $typedArtifacts[$type] = $items;
            } else {
                $unsupportedArtifacts[$type] = $items;
            }
        }

        $manifest['artifacts'] = [...$artifactId->assign($typedArtifacts), ...$unsupportedArtifacts];
        $meta = is_array($manifest['meta'] ?? null) ? $manifest['meta'] : [];
        $meta['manifest_schema_version'] = 1;
        $meta['annotation_schema_version'] = 1;
        $types = array_keys($artifacts);
        sort($types, SORT_STRING);
        $meta['scope'] = is_array($meta['scope'] ?? null)
            ? $meta['scope']
            : ['complete' => false, 'artifact_types' => $types];
        $manifest['meta'] = $meta;

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function promoteLegacyRoute(array $route): array
    {
        $metadata = is_array($route['route_metadata'] ?? null) ? $route['route_metadata'] : [];
        $raw = is_array($metadata['raw'] ?? null) ? $metadata['raw'] : [];
        $declared = $metadata['necromancer'] ?? $raw['necromancer'] ?? null;

        if (! is_array($declared)) {
            return $route;
        }

        $resolved = (new RouteAnnotationResolver)->resolve(['necromancer' => $declared]);

        $route['route_metadata'] = [
            ...$metadata,
            'necromancer' => $resolved->compatibility,
        ];

        if (! $resolved->annotations->isEmpty()) {
            $route['annotations'] = $resolved->annotations->jsonSerialize();
        }

        return $route;
    }
}
