<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use InvalidArgumentException;
use JsonException;
use LogicException;

final class ArtifactId
{
    /**
     * @var list<string>
     */
    private const TYPES = [
        'routes', 'models', 'form_requests', 'jobs', 'events', 'listeners',
        'commands', 'policies', 'enums', 'tests', 'observers', 'scheduled_tasks',
        'middleware', 'livewire_components', 'gates', 'mailables',
        'validation_rules', 'service_providers',
    ];

    public function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return list<string>
     */
    public static function supportedTypes(): array
    {
        return self::TYPES;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws JsonException
     */
    public function assign(array $artifacts): array
    {
        $assigned = [];
        $seen = [];
        $scheduledOccurrences = [];
        $hookOccurrences = [
            'before_hook' => 0,
            'after_hook' => 0,
        ];

        foreach ($artifacts as $type => $items) {
            $assigned[$type] = [];

            foreach ($items as $item) {
                $id = match ($type) {
                    'scheduled_tasks' => $this->scheduledTaskId($item, $scheduledOccurrences),
                    'gates' => $this->gateId($item, $hookOccurrences),
                    default => $this->for($type, $item),
                };

                if (isset($seen[$id])) {
                    throw new LogicException("Duplicate Artifact ID '{$id}' generated for {$type} artifacts.");
                }

                $seen[$id] = true;
                unset($item['id']);
                $assigned[$type][] = ['id' => $id, ...$item];
            }
        }

        return $assigned;
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    public function for(string $type, array $artifact): string
    {
        return match ($type) {
            'routes' => sprintf('routes:%s:%s', $this->routeMethods($artifact), $this->required($artifact, 'uri', $type)),
            'tests' => 'tests:'.$this->repositoryPath($this->required($artifact, 'file', $type)),
            'middleware' => $this->middlewareId($artifact),
            'gates', 'scheduled_tasks' => throw new InvalidArgumentException("{$type} Artifact IDs require collection context."),
            'models', 'form_requests', 'jobs', 'events', 'listeners', 'commands', 'policies', 'enums', 'observers', 'livewire_components', 'mailables', 'validation_rules', 'service_providers' => $type.':'.$this->className($artifact, $type),
            default => throw new InvalidArgumentException("Unsupported artifact type '{$type}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, int>  $occurrences
     *
     * @throws JsonException
     */
    private function scheduledTaskId(array $artifact, array &$occurrences): string
    {
        $tuple = [
            'command' => $this->required($artifact, 'command', 'scheduled_tasks'),
            'expression' => $this->required($artifact, 'expression', 'scheduled_tasks'),
            'without_overlapping' => (bool) ($artifact['without_overlapping'] ?? false),
            'run_in_background' => (bool) ($artifact['run_in_background'] ?? false),
            'even_in_maintenance' => (bool) ($artifact['even_in_maintenance'] ?? false),
            'timezone' => $artifact['timezone'] ?? null,
            'description' => $artifact['description'] ?? null,
        ];
        $digest = hash('sha256', json_encode($tuple, JSON_THROW_ON_ERROR));
        $occurrences[$digest] = ($occurrences[$digest] ?? 0) + 1;

        return "scheduled_tasks:{$digest}:{$occurrences[$digest]}";
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  array<string, int>  $occurrences
     */
    private function gateId(array $artifact, array &$occurrences): string
    {
        $kind = $this->required($artifact, 'kind', 'gates');

        if (in_array($kind, ['before_hook', 'after_hook'], true)) {
            $index = $occurrences[$kind] ?? 0;
            $occurrences[$kind] = $index + 1;

            return "gates:{$kind}:{$index}";
        }

        return 'gates:ability:'.$this->required($artifact, 'ability', 'gates');
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function middlewareId(array $artifact): string
    {
        $scope = $this->required($artifact, 'scope', 'middleware');

        return match ($scope) {
            'global' => 'middleware:global:'.$this->className($artifact, 'middleware'),
            'group' => 'middleware:group:'.$this->required($artifact, 'group', 'middleware').':'.$this->className($artifact, 'middleware'),
            'alias' => 'middleware:alias:'.$this->required($artifact, 'alias', 'middleware'),
            default => throw new InvalidArgumentException("Unsupported middleware scope '{$scope}'."),
        };
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function routeMethods(array $artifact): string
    {
        $methods = array_filter(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            explode('|', $this->required($artifact, 'method', 'routes')),
        ));

        $methods = array_values(array_unique($methods));

        if (in_array('GET', $methods, true)) {
            $methods = array_values(array_filter($methods, static fn (string $method): bool => $method !== 'HEAD'));
        }

        sort($methods, SORT_STRING);

        if ($methods === []) {
            throw new InvalidArgumentException('Route Artifact IDs require at least one HTTP method.');
        }

        return implode('|', $methods);
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function className(array $artifact, string $type): string
    {
        return ltrim($this->required($artifact, 'class', $type), '\\');
    }

    private function repositoryPath(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function required(array $artifact, string $field, string $type): string
    {
        $value = $artifact[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$type} Artifact IDs require a non-empty {$field} field.");
        }

        return $value;
    }
}
