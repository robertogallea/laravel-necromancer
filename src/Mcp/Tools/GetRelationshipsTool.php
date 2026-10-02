<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Mcp\Tools\Concerns\AnswersGraphQueries;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\ImpactDirection;
use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipResolver;
use stdClass;

final class GetRelationshipsTool extends Tool
{
    use AnswersGraphQueries;

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

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer, RelationshipResolver $resolver, Request $request): Response
    {
        $manifest = $this->loadManifest($reader);

        if ($manifest instanceof Response) {
            return $manifest;
        }

        $id = $this->resolveStart($analyzer, $manifest, (string) $request->get('artifact', ''));

        if ($id instanceof Response) {
            return $id;
        }

        $relationships = array_values(array_filter(
            $resolver->resolve($manifest),
            fn (Relationship $relationship): bool => $relationship->from === $id || $relationship->to === $id,
        ));

        return Response::json([
            'artifact' => $id,
            'relationships' => array_map(fn (Relationship $relationship): array => $this->entry($relationship, $id), $relationships),
            'warnings' => $this->manifestWarnings($manifest),
        ]);
    }

    /**
     * The Relationship as serialized in graph.json (empty metadata as an
     * object), plus its direction relative to the start.
     *
     * @return array<string, mixed>
     */
    private function entry(Relationship $relationship, string $id): array
    {
        $entry = $relationship->jsonSerialize();

        if ($entry['metadata'] === []) {
            $entry['metadata'] = new stdClass;
        }

        $entry['direction'] = ($relationship->from === $id ? ImpactDirection::Out : ImpactDirection::In)->value;

        return $entry;
    }
}
