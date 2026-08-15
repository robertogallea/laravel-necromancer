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

final class QueryModelsTool implements CanActAsTool, Tool
{
    use LoadsManifestArtifacts;

    public function __construct(
        private readonly ManifestReader $manifestReader = new ManifestReader,
        private readonly ArtifactQueryService $queryService = new ArtifactQueryService,
    ) {}

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

    public function handle(Request $request): string
    {
        $artifacts = $this->loadArtifactsByType($this->manifestReader);

        $models = $this->queryService->models(
            $artifacts,
            name: $request->has('name') ? $request->string('name')->toString() : null,
        );

        return json_encode($models, JSON_THROW_ON_ERROR);
    }
}
