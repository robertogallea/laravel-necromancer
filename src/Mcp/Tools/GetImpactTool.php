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

final class GetImpactTool extends Tool
{
    use AnswersGraphQueries;

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
                ->description('How many Relationships away to walk: 1 (default) to '.self::MAX_DEPTH.'; larger values are clamped'),
            'types' => $schema->anyOf([$schema->string(), $schema->array()->items($schema->string())])
                ->description('Only return nodes of these types (comma-separated string or array): '.implode(', ', ImpactAnalyzer::nodeTypes()).'. Unresolved nodes are hidden by any filter.'),
        ];
    }

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer, Request $request): Response
    {
        $types = $this->types($request->get('types'));
        $unknown = array_diff($types, ImpactAnalyzer::nodeTypes());

        if ($unknown !== []) {
            return $this->error('invalid_type', 'Unknown types value(s): '.implode(', ', $unknown).'. Supported: '.implode(', ', ImpactAnalyzer::nodeTypes()).'.');
        }

        $manifest = $this->loadManifest($reader);

        if ($manifest instanceof Response) {
            return $manifest;
        }

        $start = $this->resolveStart($analyzer, $manifest, (string) $request->get('artifact', ''));

        if ($start instanceof Response) {
            return $start;
        }

        $warnings = $this->manifestWarnings($manifest);
        $depth = $this->clampDepth($request->get('depth'), 1, $warnings);

        $impact = $analyzer->analyze($manifest, $start, $depth);

        return Response::json([
            'start' => $impact->start,
            'depth' => $impact->depth,
            'nodes' => $impact->nodesOfTypes($types),
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return list<string>
     */
    private function types(mixed $types): array
    {
        $types = is_string($types) ? explode(',', $types) : (array) $types;

        return array_values(array_filter(
            array_map(fn (mixed $type): string => trim((string) $type), $types),
            fn (string $type): bool => $type !== '',
        ));
    }
}
