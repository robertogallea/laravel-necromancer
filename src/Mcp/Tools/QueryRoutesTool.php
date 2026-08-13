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

final class QueryRoutesTool extends Tool
{
    use LoadsManifestArtifacts;

    public function __construct(private readonly ArtifactQueryService $queryService = new ArtifactQueryService) {}

    public function name(): string
    {
        return 'query_routes';
    }

    public function description(): string
    {
        return 'List routes from the Necromancer manifest. Optionally filter by HTTP method or a name/URI pattern.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()->description('Filter by HTTP method (GET, POST, PUT, PATCH, DELETE)'),
            'pattern' => $schema->string()->description('Case-insensitive substring to match against route name or URI'),
        ];
    }

    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $artifacts = $this->loadArtifactsByType($reader);

        $routes = $this->queryService->routes(
            $artifacts,
            method: $request->has('method') ? (string) $request->get('method') : null,
            pattern: $request->has('pattern') ? (string) $request->get('pattern') : null,
        );

        return Response::json($routes);
    }
}
