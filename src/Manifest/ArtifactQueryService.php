<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

final class ArtifactQueryService
{
    public const SUPPORTED_TYPES = [
        'routes',
        'models',
        'form_requests',
        'jobs',
        'events',
        'listeners',
        'commands',
        'observers',
        'policies',
        'enums',
        'tests',
        'scheduled_tasks',
        'middleware',
        'livewire_components',
        'gates',
        'mailables',
        'validation_rules',
        'service_providers',
    ];

    private const DEFAULT_LIMIT = 50;

    /**
     * @param  array<string, mixed>  $artifactsByType
     * @return list<array<string, mixed>>
     */
    public function routes(array $artifactsByType, ?string $method = null, ?string $pattern = null): array
    {
        $routes = (array) ($artifactsByType['routes'] ?? []);

        if ($method !== null) {
            $method = strtoupper($method);
            $routes = array_values(array_filter($routes, fn (array $r): bool => strtoupper((string) ($r['method'] ?? '')) === $method
            ));
        }

        if ($pattern !== null) {
            $needle = strtolower($pattern);
            $routes = array_values(array_filter($routes, fn (array $r): bool => str_contains(strtolower((string) ($r['name'] ?? '')), $needle) ||
                str_contains(strtolower((string) ($r['uri'] ?? '')), $needle)
            ));
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $artifactsByType
     * @return list<array<string, mixed>>
     */
    public function models(array $artifactsByType, ?string $name = null): array
    {
        $models = (array) ($artifactsByType['models'] ?? []);

        if ($name !== null) {
            $needle = strtolower($name);
            $models = array_values(array_filter($models, fn (array $m): bool => str_contains(strtolower((string) ($m['class'] ?? '')), $needle)
            ));
        }

        return $models;
    }

    /**
     * @param  array<string, mixed>  $artifactsByType
     * @return list<array<string, mixed>>
     */
    public function artifactsOfType(array $artifactsByType, string $type, ?string $query = null, ?int $limit = null): array
    {
        if (! $this->isSupportedType($type)) {
            return [];
        }

        $artifacts = (array) ($artifactsByType[$type] ?? []);

        if ($query !== null) {
            $needle = strtolower($query);
            $artifacts = array_values(array_filter(
                $artifacts,
                fn (array $artifact): bool => str_contains(strtolower(json_encode($artifact) ?: ''), $needle),
            ));
        }

        $limit = $limit !== null ? max(0, $limit) : self::DEFAULT_LIMIT;

        return array_slice($artifacts, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $artifactsByType
     * @return list<array{type: string, artifact: array<string, mixed>}>
     */
    public function search(array $artifactsByType, string $query, ?string $typeFilter = null): array
    {
        $needle = strtolower($query);
        $results = [];

        foreach ($artifactsByType as $type => $items) {
            if (! $this->isSupportedType((string) $type)) {
                continue;
            }

            if ($typeFilter !== null && $type !== $typeFilter) {
                continue;
            }

            foreach ((array) $items as $item) {
                if (str_contains(strtolower(json_encode($item) ?: ''), $needle)) {
                    $results[] = ['type' => $type, 'artifact' => $item];
                }
            }
        }

        return $results;
    }

    public function isSupportedType(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, strict: true);
    }
}
