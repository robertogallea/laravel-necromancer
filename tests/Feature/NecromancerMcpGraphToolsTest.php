<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use LaravelNecromancer\Mcp\NecromancerServer;
use LaravelNecromancer\Mcp\Tools\GetArtifactTool;
use LaravelNecromancer\Mcp\Tools\GetImpactTool;
use LaravelNecromancer\Mcp\Tools\GetRelationshipsTool;

function graphToolManifestPath(): string
{
    return base_path('storage/framework/testing/necromancer-mcp-graph.json');
}

/**
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 * @param  array<string, mixed>  $meta
 */
function writeGraphToolManifest(array $artifacts, array $meta = []): void
{
    File::ensureDirectoryExists(dirname(graphToolManifestPath()));
    File::put(graphToolManifestPath(), json_encode([
        'meta' => [
            'manifest_schema_version' => 1,
            'generated_at' => now()->addMinute()->toIso8601String(),
            'scope' => ['complete' => true, 'artifact_types' => array_keys($artifacts)],
            ...$meta,
        ],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
}

/**
 * An Order model with two relates_to User methods, its policy, a route
 * authorized against it, and a job in the checkout flow.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function graphToolArtifacts(): array
{
    return [
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
                'table' => 'orders',
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
    ];
}

/**
 * @param  array<string, mixed>  $arguments
 */
function callGraphTool(Tool $tool, array $arguments): Response
{
    return app()->call([$tool, 'handle'], ['request' => new Request($arguments)]);
}

/**
 * @return array<string, mixed>
 */
function graphToolJson(Response $response): array
{
    return json_decode((string) $response->content(), true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    File::delete(graphToolManifestPath());
    config(['necromancer.output.manifest' => graphToolManifestPath()]);
});

afterEach(fn () => File::delete(graphToolManifestPath()));

test('get_artifact returns the full payload for an exact Artifact ID and for an FQCN', function () {
    writeGraphToolManifest(graphToolArtifacts());
    $order = graphToolArtifacts()['models'][0];

    foreach (['models:App\\Models\\Order', 'App\\Models\\Order', '\\App\\Models\\Order'] as $input) {
        $response = callGraphTool(new GetArtifactTool, ['artifact' => $input]);

        expect($response->isError())->toBeFalse()
            ->and(graphToolJson($response))->toBe(['artifact' => $order, 'warnings' => []]);
    }
});

test('get_artifact rejects a Domain, Flow, or ADR ID as not_an_artifact', function () {
    writeGraphToolManifest(graphToolArtifacts());

    foreach (['flow:checkout', 'domain:billing', 'adr:docs/adr/0001-x.md'] as $input) {
        $response = callGraphTool(new GetArtifactTool, ['artifact' => $input]);

        expect($response->isError())->toBeTrue()
            ->and(graphToolJson($response)['error'])->toBe('not_an_artifact')
            ->and(graphToolJson($response)['message'])->toContain('get_relationships');
    }
});

test('get_relationships returns every Relationship the start takes part in, with direction', function () {
    writeGraphToolManifest(graphToolArtifacts());

    $json = graphToolJson(callGraphTool(new GetRelationshipsTool, ['artifact' => 'App\\Models\\Order']));
    $rows = array_map(fn (array $r): array => [$r['from'], $r['type'], $r['to'], $r['direction'], $r['metadata']['method'] ?? null], $json['relationships']);

    expect($json['artifact'])->toBe('models:App\\Models\\Order')
        ->and($json['warnings'])->toBe([])
        ->and($rows)->toBe([
            ['models:App\\Models\\Order', 'relates_to', 'models:App\\Models\\User', 'out', 'author'],
            ['models:App\\Models\\Order', 'relates_to', 'models:App\\Models\\User', 'out', 'assignee'],
            ['models:App\\Models\\Order', 'authorized_by', 'policies:App\\Policies\\OrderPolicy', 'out', null],
            ['models:App\\Models\\Order', 'belongs_to_flow', 'flow:checkout', 'out', null],
        ]);

    $user = graphToolJson(callGraphTool(new GetRelationshipsTool, ['artifact' => 'models:App\\Models\\User']));

    expect(array_column($user['relationships'], 'direction'))->toBe(['in', 'in']);
});

test('get_relationships serializes empty metadata as an object', function () {
    writeGraphToolManifest(graphToolArtifacts());

    $response = callGraphTool(new GetRelationshipsTool, ['artifact' => 'jobs:App\\Jobs\\ChargeOrder']);

    expect((string) $response->content())->toContain('"metadata":{}');
});

test('get_relationships on a Flow ID lists its members, all incoming', function () {
    writeGraphToolManifest(graphToolArtifacts());

    $json = graphToolJson(callGraphTool(new GetRelationshipsTool, ['artifact' => 'flow:checkout']));

    expect(array_map(fn (array $r): array => [$r['from'], $r['direction']], $json['relationships']))->toBe([
        ['models:App\\Models\\Order', 'in'],
        ['jobs:App\\Jobs\\ChargeOrder', 'in'],
    ]);
});

test('get_impact matches necromancer:impact --json for the same start, depth, and types, plus warnings', function (array $arguments, array $options) {
    writeGraphToolManifest(graphToolArtifacts());

    Artisan::call('necromancer:impact', ['artifact' => 'models:App\\Models\\Order', '--json' => true, ...$options]);
    $cli = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $response = callGraphTool(new GetImpactTool, ['artifact' => 'models:App\\Models\\Order', ...$arguments]);

    expect($response->isError())->toBeFalse()
        ->and(graphToolJson($response))->toBe([...$cli, 'warnings' => []]);
})->with([
    'default depth' => [[], []],
    'depth 2' => [['depth' => 2], ['--depth' => 2]],
    'types as string' => [['depth' => 2, 'types' => 'routes,flow'], ['--depth' => 2, '--type' => 'routes,flow']],
    'types as array' => [['depth' => 2, 'types' => ['routes', 'flow']], ['--depth' => 2, '--type' => 'routes,flow']],
]);

test('get_impact clamps depth above 3 and warns', function () {
    writeGraphToolManifest(graphToolArtifacts());

    $json = graphToolJson(callGraphTool(new GetImpactTool, ['artifact' => 'models:App\\Models\\Order', 'depth' => 10]));

    expect($json['depth'])->toBe(3)
        ->and($json['warnings'])->toHaveCount(1)
        ->and($json['warnings'][0])->toContain('clamped to 3');

    $zero = graphToolJson(callGraphTool(new GetImpactTool, ['artifact' => 'models:App\\Models\\Order', 'depth' => 0]));

    expect($zero['depth'])->toBe(1)
        ->and($zero['warnings'][0])->toContain('clamped to 1');
});

test('get_impact accepts a Flow ID as the start', function () {
    writeGraphToolManifest(graphToolArtifacts());

    $json = graphToolJson(callGraphTool(new GetImpactTool, ['artifact' => 'flow:checkout']));

    expect($json['start'])->toBe('flow:checkout')
        ->and(array_column($json['nodes'], 'id'))->toBe(['models:App\\Models\\Order', 'jobs:App\\Jobs\\ChargeOrder']);
});

test('get_impact rejects an unknown types value', function () {
    writeGraphToolManifest(graphToolArtifacts());

    $response = callGraphTool(new GetImpactTool, ['artifact' => 'models:App\\Models\\Order', 'types' => 'models,widgets']);

    expect($response->isError())->toBeTrue()
        ->and(graphToolJson($response)['error'])->toBe('invalid_type')
        ->and(graphToolJson($response)['message'])->toContain('widgets');
});

test('an ambiguous FQCN returns every candidate, and an unknown input is not_found', function (Tool $tool) {
    writeGraphToolManifest(graphToolArtifacts());

    $ambiguous = callGraphTool($tool, ['artifact' => 'App\\Http\\Middleware\\Authenticate']);
    $unknown = callGraphTool($tool, ['artifact' => 'App\\Models\\Ghost']);

    expect($ambiguous->isError())->toBeTrue()
        ->and(graphToolJson($ambiguous))->toMatchArray([
            'error' => 'ambiguous',
            'candidates' => ['middleware:alias:auth', 'middleware:group:web:App\\Http\\Middleware\\Authenticate'],
        ])
        ->and($unknown->isError())->toBeTrue()
        ->and(graphToolJson($unknown)['error'])->toBe('not_found')
        ->and(graphToolJson($unknown))->not->toHaveKey('candidates');
})->with([
    'get_artifact' => fn () => new GetArtifactTool,
    'get_relationships' => fn () => new GetRelationshipsTool,
    'get_impact' => fn () => new GetImpactTool,
]);

test('an unreferenced Flow ID is not_found', function (Tool $tool) {
    writeGraphToolManifest(graphToolArtifacts());

    expect(graphToolJson(callGraphTool($tool, ['artifact' => 'flow:nope']))['error'])->toBe('not_found');
})->with([
    'get_relationships' => fn () => new GetRelationshipsTool,
    'get_impact' => fn () => new GetImpactTool,
]);

test('a missing or pre-schema-v1 manifest returns manifest_not_found', function (Tool $tool) {
    $missing = callGraphTool($tool, ['artifact' => 'models:App\\Models\\Order']);

    File::put(graphToolManifestPath(), json_encode(['meta' => [], 'artifacts' => graphToolArtifacts()], JSON_THROW_ON_ERROR));
    $legacy = callGraphTool($tool, ['artifact' => 'models:App\\Models\\Order']);

    foreach ([$missing, $legacy] as $response) {
        expect($response->isError())->toBeTrue()
            ->and(graphToolJson($response)['error'])->toBe('manifest_not_found')
            ->and(graphToolJson($response)['message'])->toContain('php artisan necromancer:scan');
    }
})->with([
    'get_artifact' => fn () => new GetArtifactTool,
    'get_relationships' => fn () => new GetRelationshipsTool,
    'get_impact' => fn () => new GetImpactTool,
]);

test('a stale manifest answers with a staleness warning', function (Tool $tool) {
    $artifacts = graphToolArtifacts();
    $artifacts['models'][0]['source'] = ['file' => 'app/Models/Missing.php', 'line' => 1, 'line_end' => 2, 'hash' => 'deadbeef'];
    writeGraphToolManifest($artifacts);

    $response = callGraphTool($tool, ['artifact' => 'models:App\\Models\\Order']);

    expect($response->isError())->toBeFalse()
        ->and(graphToolJson($response)['warnings'])->toHaveCount(1)
        ->and(graphToolJson($response)['warnings'][0])->toContain('stale');
})->with([
    'get_artifact' => fn () => new GetArtifactTool,
    'get_relationships' => fn () => new GetRelationshipsTool,
    'get_impact' => fn () => new GetImpactTool,
]);

test('a partial-scope manifest answers with a warning naming the scanned types', function (Tool $tool) {
    writeGraphToolManifest(graphToolArtifacts(), ['scope' => ['complete' => false, 'artifact_types' => ['models', 'policies']]]);

    $response = callGraphTool($tool, ['artifact' => 'models:App\\Models\\Order']);

    expect($response->isError())->toBeFalse()
        ->and(graphToolJson($response)['warnings'])->toHaveCount(1)
        ->and(graphToolJson($response)['warnings'][0])->toContain('partial')->toContain('models, policies');
})->with([
    'get_artifact' => fn () => new GetArtifactTool,
    'get_relationships' => fn () => new GetRelationshipsTool,
    'get_impact' => fn () => new GetImpactTool,
]);

test('a manifest with no scope metadata is reported as partial without naming types', function () {
    File::ensureDirectoryExists(dirname(graphToolManifestPath()));
    File::put(graphToolManifestPath(), json_encode(['meta' => ['manifest_schema_version' => 1], 'artifacts' => graphToolArtifacts()], JSON_THROW_ON_ERROR));

    $warnings = graphToolJson(callGraphTool(new GetArtifactTool, ['artifact' => 'models:App\\Models\\Order']))['warnings'];

    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('partial');
});

test('necromancer server registers the graph tools', function () {
    $defaults = (new ReflectionClass(NecromancerServer::class))->getDefaultProperties();

    expect($defaults['tools'])->toContain(GetArtifactTool::class, GetRelationshipsTool::class, GetImpactTool::class)
        ->and($defaults['instructions'])->toContain('get_impact')->toContain('flow:');
});

test('get_impact declares types as a string or an array of strings', function () {
    $types = (new GetImpactTool)->toArray()['inputSchema']['properties']['types'];

    expect($types['anyOf'])->toEqual([['type' => 'string'], ['type' => 'array', 'items' => ['type' => 'string']]]);
});
