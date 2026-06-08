<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class QueryModelsTool extends Tool
{
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

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $models = $this->loadArtifacts($reader, 'models');

        if ($request->has('name')) {
            $needle = strtolower((string) $request->get('name'));
            $models = array_values(array_filter($models, fn (array $m): bool => str_contains(strtolower((string) ($m['class'] ?? '')), $needle)
            ));
        }

        return Response::json($models);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadArtifacts(ManifestReader $reader, string $type): array
    {
        try {
            $path = (string) config('necromancer.output.manifest', base_path('necromancer.json'));

            return (array) ($reader->read($path)['artifacts'][$type] ?? []);
        } catch (ManifestNotFoundException) {
            return [];
        }
    }
}
