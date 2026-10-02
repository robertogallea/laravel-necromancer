<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LaravelNecromancer\Manifest\Concerns\QueriesArtifactGraph;

final class GetRelationshipsTool implements CanActAsTool, Tool
{
    use QueriesArtifactGraph;

    public function name(): string
    {
        return 'get_relationships';
    }

    public function description(): string
    {
        return 'List every Relationship an artifact, Domain, Flow, or ADR takes part in, outgoing and incoming, in canonical order.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->required()
                ->description('An exact Artifact ID, a fully-qualified class name, or a domain:<v>, flow:<v>, or adr:<path> ID'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->graphQueries()->relationships((string) ($request['artifact'] ?? ''))->toJson();
    }
}
