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

final class QueryArtifactsTool implements CanActAsTool, Tool
{
    use LoadsManifestArtifacts;

    public function __construct(
        private readonly ManifestReader $manifestReader = new ManifestReader,
        private readonly ArtifactQueryService $queryService = new ArtifactQueryService,
    ) {}

    public function name(): string
    {
        return 'query_artifacts';
    }

    public function description(): Stringable|string
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

    public function handle(Request $request): Stringable|string
    {
        $type = $request->string('type')->toString();

        if (! $this->queryService->isSupportedType($type)) {
            return json_encode([], JSON_THROW_ON_ERROR);
        }

        $artifacts = $this->loadArtifactsByType($this->manifestReader);

        $results = $this->queryService->artifactsOfType(
            $artifacts,
            $type,
            query: $request->has('query') ? $request->string('query')->toString() : null,
            limit: $request->has('limit') ? $request->integer('limit') : null,
        );

        return json_encode($results, JSON_THROW_ON_ERROR);
    }
}
