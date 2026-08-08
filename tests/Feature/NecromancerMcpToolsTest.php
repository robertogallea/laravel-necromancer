<?php

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Mcp\NecromancerServer;
use LaravelNecromancer\Mcp\Tools\QueryArtifactsTool;
use LaravelNecromancer\Mcp\Tools\SearchArtifactsTool;

beforeEach(function () {
    File::delete(necromancerMcpManifestPath());
    config(['necromancer.output.manifest' => necromancerMcpManifestPath()]);
});

afterEach(function () {
    File::delete(necromancerMcpManifestPath());
});

test('query_artifacts returns artifacts for the requested current type', function () {
    writeNecromancerMcpManifest([
        'jobs' => [
            ['class' => 'App\\Jobs\\ArchiveClosedIssues', 'queue' => 'maintenance'],
        ],
        'events' => [
            ['class' => 'App\\Events\\IssueClosed'],
        ],
        'livewire_components' => [
            ['class' => 'App\\Livewire\\IssueForm', 'view' => 'livewire.issue-form'],
        ],
    ]);

    $results = (new QueryArtifactsTool)->handle(app(ManifestReader::class), new Request([
        'type' => 'livewire_components',
    ]));

    expect($results->content()->__toString())->toContain('IssueForm');
});

test('query_artifacts filters artifacts by query and limit', function () {
    writeNecromancerMcpManifest([
        'jobs' => [
            ['class' => 'App\\Jobs\\ArchiveClosedIssues', 'queue' => 'maintenance'],
            ['class' => 'App\\Jobs\\ArchiveCompletedProjects', 'queue' => 'maintenance'],
            ['class' => 'App\\Jobs\\NotifyAssignee', 'queue' => 'notifications'],
        ],
    ]);

    $results = (new QueryArtifactsTool)->handle(app(ManifestReader::class), new Request([
        'type' => 'jobs',
        'query' => 'archive',
        'limit' => 1,
    ]));

    $json = json_decode($results->content()->__toString(), true);
    expect($json)->toHaveCount(1)
        ->and($json[0]['class'])->toBe('App\\Jobs\\ArchiveClosedIssues');
});

test('query_artifacts uses form_requests and does not support legacy requests', function () {
    writeNecromancerMcpManifest([
        'form_requests' => [
            ['id' => 'form_requests:App\\Http\\Requests\\StoreIssueRequest', 'class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'requests' => [
            ['class' => 'App\\Http\\Requests\\LegacyIssueRequest'],
        ],
    ]);

    $tool = new QueryArtifactsTool;

    $formRequests = json_decode($tool->handle(app(ManifestReader::class), new Request(['type' => 'form_requests']))->content()->__toString(), true);
    $legacy = json_decode($tool->handle(app(ManifestReader::class), new Request(['type' => 'requests']))->content()->__toString(), true);

    expect($formRequests)->toBe([['id' => 'form_requests:App\\Http\\Requests\\StoreIssueRequest', 'class' => 'App\\Http\\Requests\\StoreIssueRequest']])
        ->and($legacy)->toBe([]);
});

test('query_artifacts returns an empty list when the manifest is missing', function () {
    File::delete(necromancerMcpManifestPath());

    $results = (new QueryArtifactsTool)->handle(app(ManifestReader::class), new Request([
        'type' => 'jobs',
    ]));

    expect(json_decode($results->content()->__toString(), true))->toBe([]);
});

test('search_artifacts supports current type filters and ignores legacy requests', function () {
    writeNecromancerMcpManifest([
        'form_requests' => [
            ['id' => 'form_requests:App\\Http\\Requests\\StoreIssueRequest', 'class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'requests' => [
            ['class' => 'App\\Http\\Requests\\LegacyIssueRequest'],
        ],
        'validation_rules' => [
            ['class' => 'App\\Rules\\ProjectMember'],
        ],
    ]);

    $tool = new SearchArtifactsTool;

    $found = json_decode($tool->handle(app(ManifestReader::class), new Request([
        'type' => 'form_requests',
        'query' => 'StoreIssue',
    ]))->content()->__toString(), true);

    $notFound = json_decode($tool->handle(app(ManifestReader::class), new Request([
        'query' => 'LegacyIssue',
    ]))->content()->__toString(), true);

    expect($found)->toBe([
        ['type' => 'form_requests', 'artifact' => ['id' => 'form_requests:App\\Http\\Requests\\StoreIssueRequest', 'class' => 'App\\Http\\Requests\\StoreIssueRequest']],
    ])->and($notFound)->toBe([]);
});

test('necromancer server registers query_artifacts', function () {
    $defaults = (new ReflectionClass(NecromancerServer::class))->getDefaultProperties();

    expect($defaults['tools'])->toContain(QueryArtifactsTool::class);
});

function necromancerMcpManifestPath(): string
{
    return base_path('storage/framework/testing/necromancer-mcp.json');
}

/**
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 */
function writeNecromancerMcpManifest(array $artifacts): void
{
    File::ensureDirectoryExists(dirname(necromancerMcpManifestPath()));
    File::put(necromancerMcpManifestPath(), json_encode([
        'meta' => ['manifest_schema_version' => 1],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
}
