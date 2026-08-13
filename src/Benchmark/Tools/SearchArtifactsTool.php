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

final class SearchArtifactsTool implements CanActAsTool, Tool
{
    use LoadsManifestArtifacts;

    public function __construct(
        private readonly ManifestReader $manifestReader = new ManifestReader,
        private readonly ArtifactQueryService $queryService = new ArtifactQueryService,
    ) {}

    public function name(): string
    {
        return 'search_artifacts';
    }

    public function description(): Stringable|string
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

    public function handle(Request $request): Stringable|string
    {
        $artifacts = $this->loadArtifactsByType($this->manifestReader);

        $results = $this->queryService->search(
            $artifacts,
            $request->string('query')->toString(),
            typeFilter: $request->has('type') ? $request->string('type')->toString() : null,
        );

        return json_encode($results, JSON_THROW_ON_ERROR);
    }
}
