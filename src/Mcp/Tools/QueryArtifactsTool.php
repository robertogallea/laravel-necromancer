<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class QueryArtifactsTool extends Tool
{
    private const DEFAULT_LIMIT = 50;

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
        return 'query_artifacts';
    }

    public function description(): string
    {
        return 'List artifacts of any current type from the Necromancer manifest. Optionally filter by a JSON substring.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->required()
                ->description('Artifact type to list: routes, models, form_requests, jobs, events, listeners, commands, observers, policies, enums, tests, scheduled_tasks, middleware, livewire_components, gates, mailables, validation_rules, service_providers'),
            'query' => $schema->string()
                ->description('Optional case-insensitive string to match against each artifact JSON payload'),
            'limit' => $schema->integer()
                ->description('Optional maximum number of artifacts to return'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $type = (string) ($request->get('type') ?? '');

        if (! in_array($type, self::SUPPORTED_TYPES, strict: true)) {
            return Response::json([]);
        }

        $artifacts = $this->loadArtifacts($reader, $type);

        if ($request->has('query')) {
            $needle = strtolower((string) $request->get('query'));
            $artifacts = array_values(array_filter(
                $artifacts,
                fn (array $artifact): bool => str_contains(strtolower(json_encode($artifact) ?: ''), $needle),
            ));
        }

        $limit = $request->has('limit')
            ? max(0, (int) $request->get('limit'))
            : self::DEFAULT_LIMIT;

        return Response::json(array_slice($artifacts, 0, $limit));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadArtifacts(ManifestReader $reader, string $type): array
    {
        try {
            $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));

            return (array) ($reader->read($path)['artifacts'][$type] ?? []);
        } catch (ManifestNotFoundException) {
            return [];
        }
    }
}
