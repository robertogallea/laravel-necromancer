<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ArtifactQueryService;
use LaravelNecromancer\Manifest\Concerns\LoadsManifestArtifacts;
use LaravelNecromancer\Manifest\ManifestReader;

final class SearchArtifactsTool extends Tool
{
    use LoadsManifestArtifacts;

    public function __construct(private readonly ArtifactQueryService $queryService = new ArtifactQueryService) {}

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

    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $artifacts = $this->loadArtifactsByType($reader);

        $results = $this->queryService->search(
            $artifacts,
            (string) ($request->get('query') ?? ''),
            typeFilter: $request->has('type') ? (string) $request->get('type') : null,
        );

        return Response::json($results);
    }
}
