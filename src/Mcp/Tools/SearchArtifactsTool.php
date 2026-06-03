<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class SearchArtifactsTool extends Tool
{
    public function name(): string
    {
        return 'search_artifacts';
    }

    public function description(): string
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
                ->description('Restrict search to one artifact type: routes, models, jobs, events, listeners, commands, policies, enums, requests'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{type: string, artifact: array<string, mixed>}>
     */
    public function handle(ManifestReader $reader, array $input): mixed
    {
        $needle = strtolower((string) ($input['query'] ?? ''));
        $typeFilter = isset($input['type']) ? (string) $input['type'] : null;
        $results = [];

        try {
            $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));
            $artifacts = (array) ($reader->read($path)['artifacts'] ?? []);
        } catch (ManifestNotFoundException) {
            return [];
        }

        foreach ($artifacts as $type => $items) {
            if ($typeFilter !== null && $type !== $typeFilter) {
                continue;
            }

            foreach ((array) $items as $item) {
                if (str_contains(strtolower(json_encode($item) ?: ''), $needle)) {
                    $results[] = ['type' => $type, 'artifact' => $item];
                }
            }
        }

        return $results;
    }
}
