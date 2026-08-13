<?php

use Illuminate\Support\Facades\File;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use LaravelNecromancer\Benchmark\Tools\QueryArtifactsTool;
use LaravelNecromancer\Benchmark\Tools\QueryModelsTool;
use LaravelNecromancer\Benchmark\Tools\QueryRoutesTool;
use LaravelNecromancer\Benchmark\Tools\SearchArtifactsTool;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Mcp\Tools\QueryArtifactsTool as McpQueryArtifactsTool;
use LaravelNecromancer\Mcp\Tools\QueryModelsTool as McpQueryModelsTool;
use LaravelNecromancer\Mcp\Tools\QueryRoutesTool as McpQueryRoutesTool;
use LaravelNecromancer\Mcp\Tools\SearchArtifactsTool as McpSearchArtifactsTool;

beforeEach(function () {
    File::delete(necromancerBenchmarkMcpManifestPath());
    config(['necromancer.output.manifest' => necromancerBenchmarkMcpManifestPath()]);
});

afterEach(function () {
    File::delete(necromancerBenchmarkMcpManifestPath());
});

test('query_routes filters by HTTP method', function () {
    writeNecromancerBenchmarkMcpManifest([
        'routes' => [
            ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects'],
            ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects'],
        ],
    ]);

    $results = json_decode((string) (new QueryRoutesTool)->handle(new Request(['method' => 'post'])), true);

    expect($results)->toBe([['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects']]);
});

test('query_models filters by a case-insensitive substring against the class name', function () {
    writeNecromancerBenchmarkMcpManifest([
        'models' => [
            ['class' => 'App\\Models\\Order'],
            ['class' => 'App\\Models\\Customer'],
        ],
    ]);

    $results = json_decode((string) (new QueryModelsTool)->handle(new Request(['name' => 'order'])), true);

    expect($results)->toBe([['class' => 'App\\Models\\Order']]);
});

test('query_artifacts returns artifacts for the requested current type', function () {
    writeNecromancerBenchmarkMcpManifest([
        'jobs' => [
            ['class' => 'App\\Jobs\\ArchiveClosedIssues'],
        ],
        'livewire_components' => [
            ['class' => 'App\\Livewire\\IssueForm'],
        ],
    ]);

    $results = json_decode((string) (new QueryArtifactsTool)->handle(new Request(['type' => 'livewire_components'])), true);

    expect($results)->toBe([['class' => 'App\\Livewire\\IssueForm']]);
});

test('query_artifacts returns an empty list for an unsupported type', function () {
    writeNecromancerBenchmarkMcpManifest([
        'requests' => [['class' => 'App\\Http\\Requests\\LegacyIssueRequest']],
    ]);

    $results = json_decode((string) (new QueryArtifactsTool)->handle(new Request(['type' => 'requests'])), true);

    expect($results)->toBe([]);
});

test('search_artifacts finds matches across artifact types', function () {
    writeNecromancerBenchmarkMcpManifest([
        'form_requests' => [
            ['class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\NotifyAssignee'],
        ],
    ]);

    $results = json_decode((string) (new SearchArtifactsTool)->handle(new Request(['query' => 'StoreIssue'])), true);

    expect($results)->toBe([
        ['type' => 'form_requests', 'artifact' => ['class' => 'App\\Http\\Requests\\StoreIssueRequest']],
    ]);
});

test('search_artifacts restricts to a single type when a type filter is given', function () {
    writeNecromancerBenchmarkMcpManifest([
        'form_requests' => [
            ['class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\StoreIssueAttachment'],
        ],
    ]);

    $results = json_decode((string) (new SearchArtifactsTool)->handle(new Request(['query' => 'StoreIssue', 'type' => 'jobs'])), true);

    expect($results)->toBe([
        ['type' => 'jobs', 'artifact' => ['class' => 'App\\Jobs\\StoreIssueAttachment']],
    ]);
});

test('all four tools return an empty list when the manifest is missing', function () {
    File::delete(necromancerBenchmarkMcpManifestPath());

    expect(json_decode((string) (new QueryRoutesTool)->handle(new Request([])), true))->toBe([])
        ->and(json_decode((string) (new QueryModelsTool)->handle(new Request([])), true))->toBe([])
        ->and(json_decode((string) (new QueryArtifactsTool)->handle(new Request(['type' => 'jobs'])), true))->toBe([])
        ->and(json_decode((string) (new SearchArtifactsTool)->handle(new Request(['query' => 'anything'])), true))->toBe([]);
});

test('query_routes returns the same result as its MCP-server equivalent for the same arguments', function () {
    writeNecromancerBenchmarkMcpManifest([
        'routes' => [
            ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects'],
            ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects'],
            ['name' => 'issues.index', 'method' => 'GET', 'uri' => '/issues'],
        ],
    ]);

    $benchmarkResult = json_decode((string) (new QueryRoutesTool)->handle(new Request(['method' => 'GET', 'pattern' => 'projects'])), true);
    $mcpResult = json_decode((new McpQueryRoutesTool)->handle(app(ManifestReader::class), new McpRequest(['method' => 'GET', 'pattern' => 'projects']))->content()->__toString(), true);

    expect($benchmarkResult)->toBe($mcpResult)
        ->and($benchmarkResult)->not->toBeEmpty();
});

test('query_models returns the same result as its MCP-server equivalent for the same arguments', function () {
    writeNecromancerBenchmarkMcpManifest([
        'models' => [
            ['class' => 'App\\Models\\Order'],
            ['class' => 'App\\Models\\Customer'],
        ],
    ]);

    $benchmarkResult = json_decode((string) (new QueryModelsTool)->handle(new Request(['name' => 'order'])), true);
    $mcpResult = json_decode((new McpQueryModelsTool)->handle(app(ManifestReader::class), new McpRequest(['name' => 'order']))->content()->__toString(), true);

    expect($benchmarkResult)->toBe($mcpResult)
        ->and($benchmarkResult)->not->toBeEmpty();
});

test('query_artifacts returns the same result as its MCP-server equivalent for the same arguments', function () {
    writeNecromancerBenchmarkMcpManifest([
        'jobs' => [
            ['class' => 'App\\Jobs\\ArchiveClosedIssues', 'queue' => 'maintenance'],
            ['class' => 'App\\Jobs\\NotifyAssignee', 'queue' => 'notifications'],
        ],
    ]);

    $benchmarkResult = json_decode((string) (new QueryArtifactsTool)->handle(new Request(['type' => 'jobs', 'query' => 'archive'])), true);
    $mcpResult = json_decode((new McpQueryArtifactsTool)->handle(app(ManifestReader::class), new McpRequest(['type' => 'jobs', 'query' => 'archive']))->content()->__toString(), true);

    expect($benchmarkResult)->toBe($mcpResult)
        ->and($benchmarkResult)->not->toBeEmpty();
});

test('search_artifacts returns the same result as its MCP-server equivalent for the same arguments', function () {
    writeNecromancerBenchmarkMcpManifest([
        'form_requests' => [
            ['class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\StoreIssueAttachment'],
        ],
    ]);

    $benchmarkResult = json_decode((string) (new SearchArtifactsTool)->handle(new Request(['query' => 'StoreIssue'])), true);
    $mcpResult = json_decode((new McpSearchArtifactsTool)->handle(app(ManifestReader::class), new McpRequest(['query' => 'StoreIssue']))->content()->__toString(), true);

    expect($benchmarkResult)->toBe($mcpResult)
        ->and($benchmarkResult)->not->toBeEmpty();
});

test('all four tools expose the same name as their MCP-server equivalent', function () {
    expect((new QueryRoutesTool)->name())->toBe('query_routes')
        ->and((new QueryModelsTool)->name())->toBe('query_models')
        ->and((new QueryArtifactsTool)->name())->toBe('query_artifacts')
        ->and((new SearchArtifactsTool)->name())->toBe('search_artifacts');
});

function necromancerBenchmarkMcpManifestPath(): string
{
    return base_path('storage/framework/testing/necromancer-benchmark-mcp.json');
}

/**
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 */
function writeNecromancerBenchmarkMcpManifest(array $artifacts): void
{
    File::ensureDirectoryExists(dirname(necromancerBenchmarkMcpManifestPath()));
    File::put(necromancerBenchmarkMcpManifestPath(), json_encode([
        'meta' => ['manifest_schema_version' => 1],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
}
