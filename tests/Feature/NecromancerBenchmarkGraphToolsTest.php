<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool as AiTool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Server\Tool as McpTool;
use LaravelNecromancer\Benchmark\Tools\GetArtifactTool;
use LaravelNecromancer\Benchmark\Tools\GetImpactTool;
use LaravelNecromancer\Benchmark\Tools\GetRelationshipsTool;
use LaravelNecromancer\Mcp\Tools\GetArtifactTool as McpGetArtifactTool;
use LaravelNecromancer\Mcp\Tools\GetImpactTool as McpGetImpactTool;
use LaravelNecromancer\Mcp\Tools\GetRelationshipsTool as McpGetRelationshipsTool;

function benchmarkGraphManifestPath(): string
{
    return base_path('storage/framework/testing/necromancer-benchmark-graph.json');
}

/**
 * An Order model relating twice to User, its policy, a route authorized
 * against it, a job in the checkout flow, and a middleware class
 * registered twice (so its FQCN is ambiguous).
 */
function writeBenchmarkGraphManifest(): void
{
    File::ensureDirectoryExists(dirname(benchmarkGraphManifestPath()));
    File::put(benchmarkGraphManifestPath(), json_encode([
        'meta' => [
            'manifest_schema_version' => 1,
            'generated_at' => now()->addMinute()->toIso8601String(),
            'scope' => ['complete' => false, 'artifact_types' => ['routes', 'models', 'jobs', 'policies', 'middleware']],
        ],
        'artifacts' => [
            'routes' => [[
                'id' => 'routes:GET:orders',
                'method' => 'GET',
                'uri' => 'orders',
                'authorization' => [['ability' => 'viewAny', 'models' => ['App\\Models\\Order']]],
            ]],
            'models' => [
                [
                    'id' => 'models:App\\Models\\Order',
                    'class' => 'App\\Models\\Order',
                    'policy' => 'App\\Policies\\OrderPolicy',
                    'relationships' => [
                        ['type' => 'belongsTo', 'related' => 'App\\Models\\User', 'method' => 'author'],
                        ['type' => 'belongsTo', 'related' => 'App\\Models\\User', 'method' => 'assignee'],
                    ],
                    'annotations' => ['flow' => 'checkout'],
                ],
                ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User'],
            ],
            'jobs' => [['id' => 'jobs:App\\Jobs\\ChargeOrder', 'class' => 'App\\Jobs\\ChargeOrder', 'annotations' => ['flow' => 'checkout']]],
            'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order']],
            'middleware' => [
                ['id' => 'middleware:alias:auth', 'alias' => 'auth', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'alias', 'group' => null],
                ['id' => 'middleware:group:web:App\\Http\\Middleware\\Authenticate', 'alias' => null, 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'group', 'group' => 'web'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));
}

/**
 * The benchmark tool's string and the MCP tool's response text for the
 * same arguments.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: string, 1: string}
 */
function benchmarkAndMcpGraphOutput(AiTool $benchmarkTool, McpTool $mcpTool, array $arguments): array
{
    $mcpResponse = app()->call([$mcpTool, 'handle'], ['request' => new McpRequest($arguments)]);

    return [(string) $benchmarkTool->handle(new Request($arguments)), (string) $mcpResponse->content()];
}

beforeEach(function () {
    File::delete(benchmarkGraphManifestPath());
    config(['necromancer.output.manifest' => benchmarkGraphManifestPath()]);
});

afterEach(fn () => File::delete(benchmarkGraphManifestPath()));

test('get_artifact returns exactly the MCP tool\'s JSON, including warnings and error bodies', function (array $arguments) {
    writeBenchmarkGraphManifest();

    [$benchmark, $mcp] = benchmarkAndMcpGraphOutput(new GetArtifactTool, new McpGetArtifactTool, $arguments);

    expect($benchmark)->toBe($mcp)->and($benchmark)->not->toBe('');
})->with([
    'exact ID' => [['artifact' => 'models:App\\Models\\Order']],
    'FQCN' => [['artifact' => 'App\\Policies\\OrderPolicy']],
    'concept ID' => [['artifact' => 'flow:checkout']],
    'ambiguous FQCN' => [['artifact' => 'App\\Http\\Middleware\\Authenticate']],
    'unknown' => [['artifact' => 'App\\Models\\Nope']],
]);

test('get_relationships returns exactly the MCP tool\'s JSON, including warnings and error bodies', function (array $arguments) {
    writeBenchmarkGraphManifest();

    [$benchmark, $mcp] = benchmarkAndMcpGraphOutput(new GetRelationshipsTool, new McpGetRelationshipsTool, $arguments);

    expect($benchmark)->toBe($mcp)->and($benchmark)->not->toBe('');
})->with([
    'model with metadata' => [['artifact' => 'models:App\\Models\\Order']],
    'flow members' => [['artifact' => 'flow:checkout']],
    'ambiguous FQCN' => [['artifact' => 'App\\Http\\Middleware\\Authenticate']],
    'unreferenced flow' => [['artifact' => 'flow:nope']],
]);

test('get_impact returns exactly the MCP tool\'s JSON, including warnings and error bodies', function (array $arguments) {
    writeBenchmarkGraphManifest();

    [$benchmark, $mcp] = benchmarkAndMcpGraphOutput(new GetImpactTool, new McpGetImpactTool, $arguments);

    expect($benchmark)->toBe($mcp)->and($benchmark)->not->toBe('');
})->with([
    'default depth' => [['artifact' => 'App\\Models\\Order']],
    'clamped depth' => [['artifact' => 'App\\Models\\Order', 'depth' => 7]],
    'types as string' => [['artifact' => 'models:App\\Models\\Order', 'depth' => 2, 'types' => 'routes,policies']],
    'types as array' => [['artifact' => 'flow:checkout', 'types' => ['jobs']]],
    'unknown type' => [['artifact' => 'App\\Models\\Order', 'types' => 'nope']],
]);

test('every benchmark graph tool returns the MCP manifest_not_found body when the manifest is missing', function (AiTool $benchmarkTool, McpTool $mcpTool) {
    [$benchmark, $mcp] = benchmarkAndMcpGraphOutput($benchmarkTool, $mcpTool, ['artifact' => 'App\\Models\\Order']);

    expect($benchmark)->toBe($mcp)
        ->and(json_decode($benchmark, true)['error'])->toBe('manifest_not_found');
})->with([
    'get_artifact' => [fn () => new GetArtifactTool, fn () => new McpGetArtifactTool],
    'get_relationships' => [fn () => new GetRelationshipsTool, fn () => new McpGetRelationshipsTool],
    'get_impact' => [fn () => new GetImpactTool, fn () => new McpGetImpactTool],
]);

test('the benchmark graph tools expose the MCP tools\' names and input schemas', function (AiTool $benchmarkTool, McpTool $mcpTool) {
    $schema = new JsonSchemaTypeFactory;

    expect($benchmarkTool->name())->toBe($mcpTool->name())
        ->and(array_map(fn ($type) => $type->toArray(), $benchmarkTool->schema($schema)))
        ->toBe(array_map(fn ($type) => $type->toArray(), $mcpTool->schema($schema)));
})->with([
    'get_artifact' => [fn () => new GetArtifactTool, fn () => new McpGetArtifactTool],
    'get_relationships' => [fn () => new GetRelationshipsTool, fn () => new McpGetRelationshipsTool],
    'get_impact' => [fn () => new GetImpactTool, fn () => new McpGetImpactTool],
]);
