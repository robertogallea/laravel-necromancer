<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class QueryRoutesTool extends Tool
{
    public function name(): string
    {
        return 'query_routes';
    }

    public function description(): string
    {
        return 'List routes from the Necromancer manifest. Optionally filter by HTTP method or a name/URI pattern.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()->description('Filter by HTTP method (GET, POST, PUT, PATCH, DELETE)'),
            'pattern' => $schema->string()->description('Case-insensitive substring to match against route name or URI'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function handle(ManifestReader $reader, array $input): mixed
    {
        $routes = $this->loadArtifacts($reader, 'routes');

        if (isset($input['method'])) {
            $method = strtoupper((string) $input['method']);
            $routes = array_values(array_filter($routes, fn (array $r): bool => strtoupper((string) ($r['method'] ?? '')) === $method
            ));
        }

        if (isset($input['pattern'])) {
            $needle = strtolower((string) $input['pattern']);
            $routes = array_values(array_filter($routes, fn (array $r): bool => str_contains(strtolower((string) ($r['name'] ?? '')), $needle) ||
                str_contains(strtolower((string) ($r['uri'] ?? '')), $needle)
            ));
        }

        return $routes;
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
