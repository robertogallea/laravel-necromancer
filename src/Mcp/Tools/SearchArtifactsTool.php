<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class SearchArtifactsTool extends Tool
{
    private const SUPPORTED_TYPES = [
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

    public function name(): string
    {
        return 'search_artifacts';
    }

    public function description(): string
    {
        return 'Full-text search across all artifact types in the Necromancer manifest. Returns matching artifacts tagged with their type.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()
                ->description('Case-insensitive string to search for across all artifact JSON fields'),
            'type' => $schema->string()
                ->description('Restrict search to one artifact type: routes, models, form_requests, jobs, events, listeners, commands, observers, policies, enums, tests, scheduled_tasks, middleware, livewire_components, gates, mailables, validation_rules, service_providers'),
        ];
    }

    /**
     * @return list<array{type: string, artifact: array<string, mixed>}>
     */
    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $needle = strtolower((string) ($request->get('query') ?? ''));
        $typeFilter = $request->has('type') ? (string) $request->get('type') : null;
        $results = [];

        try {
            $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));
            $artifacts = (array) ($reader->read($path)['artifacts'] ?? []);
        } catch (ManifestNotFoundException) {
            return Response::json([]);
        }

        foreach ($artifacts as $type => $items) {
            if (! in_array($type, self::SUPPORTED_TYPES, strict: true)) {
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

        return Response::json($results);
    }
}
