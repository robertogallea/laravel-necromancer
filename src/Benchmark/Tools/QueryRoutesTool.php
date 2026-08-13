<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LaravelNecromancer\Manifest\ArtifactQueryService;
use LaravelNecromancer\Manifest\Concerns\LoadsManifestArtifacts;
use LaravelNecromancer\Manifest\ManifestReader;
use Stringable;

final class QueryRoutesTool implements CanActAsTool, Tool
{
    use LoadsManifestArtifacts;

    public function __construct(
        private readonly ManifestReader $manifestReader = new ManifestReader,
        private readonly ArtifactQueryService $queryService = new ArtifactQueryService,
    ) {}

    public function name(): string
    {
        return 'query_routes';
    }

    public function description(): Stringable|string
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

    public function handle(Request $request): Stringable|string
    {
        $artifacts = $this->loadArtifactsByType($this->manifestReader);

        $routes = $this->queryService->routes(
            $artifacts,
            method: $request->has('method') ? $request->string('method')->toString() : null,
            pattern: $request->has('pattern') ? $request->string('pattern')->toString() : null,
        );

        return json_encode($routes, JSON_THROW_ON_ERROR);
    }
}
