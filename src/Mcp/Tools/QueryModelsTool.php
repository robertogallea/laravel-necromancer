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

final class QueryModelsTool extends Tool
{
    use LoadsManifestArtifacts;

    public function __construct(private readonly ArtifactQueryService $queryService = new ArtifactQueryService) {}

    public function name(): string
    {
        return 'query_models';
    }

    public function description(): string
    {
        return 'List Eloquent models from the Necromancer manifest with their tables, fillable fields, casts, and relationships.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Case-insensitive substring to match against the model class name'),
        ];
    }

    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $artifacts = $this->loadArtifactsByType($reader);

        $models = $this->queryService->models(
            $artifacts,
            name: $request->has('name') ? (string) $request->get('name') : null,
        );

        return Response::json($models);
    }
}
