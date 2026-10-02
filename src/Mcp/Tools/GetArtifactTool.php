<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ArtifactId;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Mcp\Tools\Concerns\AnswersGraphQueries;
use LaravelNecromancer\Relationships\ImpactAnalyzer;

final class GetArtifactTool extends Tool
{
    use AnswersGraphQueries;

    public function name(): string
    {
        return 'get_artifact';
    }

    public function description(): string
    {
        return 'Return one artifact\'s full manifest payload, by exact Artifact ID or fully-qualified class name.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact' => $schema->string()->required()
                ->description('An exact Artifact ID (e.g. models:App\\Models\\Order) or a fully-qualified class name'),
        ];
    }

    public function handle(ManifestReader $reader, ImpactAnalyzer $analyzer, Request $request): Response
    {
        $input = (string) $request->get('artifact', '');

        if (ImpactAnalyzer::isConceptId($input)) {
            return $this->error('not_an_artifact', "'{$input}' is a Domain, Flow, or ADR, not a collected artifact. Use get_relationships to list what belongs to or references it.");
        }

        $manifest = $this->loadManifest($reader);

        if ($manifest instanceof Response) {
            return $manifest;
        }

        $id = $this->resolveStart($analyzer, $manifest, $input);

        if ($id instanceof Response) {
            return $id;
        }

        return Response::json([
            'artifact' => $this->payload($manifest, $id),
            'warnings' => $this->manifestWarnings($manifest),
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function payload(array $manifest, string $id): array
    {
        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                if (is_array($artifact) && ($artifact['id'] ?? null) === $id) {
                    return $artifact;
                }
            }
        }

        return [];
    }
}
