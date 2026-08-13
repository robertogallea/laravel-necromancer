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

final class QueryArtifactsTool extends Tool
{
    use LoadsManifestArtifacts;

    public function __construct(private readonly ArtifactQueryService $queryService = new ArtifactQueryService) {}

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

    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $type = (string) ($request->get('type') ?? '');

        if (! $this->queryService->isSupportedType($type)) {
            return Response::json([]);
        }

        $artifacts = $this->loadArtifactsByType($reader);

        $results = $this->queryService->artifactsOfType(
            $artifacts,
            $type,
            query: $request->has('query') ? (string) $request->get('query') : null,
            limit: $request->has('limit') ? (int) $request->get('limit') : null,
        );

        return Response::json($results);
    }
}
