<?php

declare(strict_types=1);

namespace LaravelNecromancer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
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
     * @return list<array<string, mixed>>
     */
    public function handle(ManifestReader $reader, Request $request): mixed
    {
        $routes = $this->loadArtifacts($reader, 'routes');

        if ($request->has('method')) {
            $method = strtoupper((string) $request->get('method'));
            $routes = array_values(array_filter($routes, fn (array $r): bool => strtoupper((string) ($r['method'] ?? '')) === $method
            ));
        }

        if ($request->has('pattern')) {
            $needle = strtolower((string) $request->get('pattern'));
            $routes = array_values(array_filter($routes, fn (array $r): bool => str_contains(strtolower((string) ($r['name'] ?? '')), $needle) ||
                str_contains(strtolower((string) ($r['uri'] ?? '')), $needle)
            ));
        }

        return Response::json($routes);
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
