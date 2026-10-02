<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LaravelNecromancer\Manifest\Concerns\QueriesArtifactGraph;
use LaravelNecromancer\Manifest\GraphQueryService;
use LaravelNecromancer\Relationships\ImpactAnalyzer;

final class GetImpactTool implements CanActAsTool, Tool
{
    use QueriesArtifactGraph;

    public function name(): string
    {
        return 'get_impact';
    }

    public function description(): string
    {
        return 'List everything reachable from an artifact, Domain, Flow, or ADR through Relationships, in both directions, each at its shortest distance. Domains, Flows, ADRs, middleware, and tests are reported but never walked past.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->required()
                ->description('An exact Artifact ID, a fully-qualified class name, or a domain:<v>, flow:<v>, or adr:<path> ID'),
            'depth' => $schema->integer()
                ->description('How many Relationships away to walk: 1 (default) to '.GraphQueryService::MAX_DEPTH.'; larger values are clamped'),
            'types' => $schema->anyOf([$schema->string(), $schema->array()->items($schema->string())])
                ->description('Only return nodes of these types (comma-separated string or array): '.implode(', ', ImpactAnalyzer::nodeTypes()).'. Unresolved nodes are hidden by any filter.'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->graphQueries()->impact(
            (string) ($request['artifact'] ?? ''),
            $request['depth'] ?? null,
            $request['types'] ?? null,
        )->toJson();
    }
}
