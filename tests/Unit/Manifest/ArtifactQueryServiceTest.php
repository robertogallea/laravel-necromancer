<?php

use LaravelNecromancer\Manifest\ArtifactQueryService;

// routes()

test('routes() returns all routes when no filter is given', function () {
    $artifacts = ['routes' => [
        ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects'],
        ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects'],
    ]];

    expect((new ArtifactQueryService)->routes($artifacts))->toBe($artifacts['routes']);
});

test('routes() filters by HTTP method case-insensitively', function () {
    $artifacts = ['routes' => [
        ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects'],
        ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects'],
    ]];

    $result = (new ArtifactQueryService)->routes($artifacts, method: 'get');

    expect($result)->toBe([['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects']]);
});

test('routes() filters by a case-insensitive substring against name or uri', function () {
    $artifacts = ['routes' => [
        ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects'],
        ['name' => 'issues.index', 'method' => 'GET', 'uri' => '/issues'],
    ]];

    $byName = (new ArtifactQueryService)->routes($artifacts, pattern: 'PROJECTS');
    $byUri = (new ArtifactQueryService)->routes($artifacts, pattern: '/issues');

    expect($byName)->toBe([['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects']])
        ->and($byUri)->toBe([['name' => 'issues.index', 'method' => 'GET', 'uri' => '/issues']]);
});

test('routes() combines method and pattern filters', function () {
    $artifacts = ['routes' => [
        ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects'],
        ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects'],
    ]];

    $result = (new ArtifactQueryService)->routes($artifacts, method: 'POST', pattern: 'projects');

    expect($result)->toBe([['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects']]);
});

test('routes() returns an empty list when the manifest has no routes key', function () {
    expect((new ArtifactQueryService)->routes([]))->toBe([]);
});

// models()

test('models() returns all models when no filter is given', function () {
    $artifacts = ['models' => [
        ['class' => 'App\\Models\\Order'],
        ['class' => 'App\\Models\\Customer'],
    ]];

    expect((new ArtifactQueryService)->models($artifacts))->toBe($artifacts['models']);
});

test('models() filters by a case-insensitive substring against the class name', function () {
    $artifacts = ['models' => [
        ['class' => 'App\\Models\\Order'],
        ['class' => 'App\\Models\\Customer'],
    ]];

    $result = (new ArtifactQueryService)->models($artifacts, name: 'order');

    expect($result)->toBe([['class' => 'App\\Models\\Order']]);
});

// artifactsOfType()

test('artifactsOfType() returns artifacts for a supported type', function () {
    $artifacts = ['jobs' => [
        ['class' => 'App\\Jobs\\ArchiveClosedIssues'],
    ]];

    expect((new ArtifactQueryService)->artifactsOfType($artifacts, 'jobs'))->toBe($artifacts['jobs']);
});

test('artifactsOfType() returns an empty list for an unsupported type', function () {
    $artifacts = ['requests' => [
        ['class' => 'App\\Http\\Requests\\LegacyIssueRequest'],
    ]];

    expect((new ArtifactQueryService)->artifactsOfType($artifacts, 'requests'))->toBe([]);
});

test('artifactsOfType() filters by a case-insensitive substring against the JSON payload', function () {
    $artifacts = ['jobs' => [
        ['class' => 'App\\Jobs\\ArchiveClosedIssues', 'queue' => 'maintenance'],
        ['class' => 'App\\Jobs\\NotifyAssignee', 'queue' => 'notifications'],
    ]];

    $result = (new ArtifactQueryService)->artifactsOfType($artifacts, 'jobs', query: 'archive');

    expect($result)->toBe([['class' => 'App\\Jobs\\ArchiveClosedIssues', 'queue' => 'maintenance']]);
});

test('artifactsOfType() defaults the limit to 50 and honours an explicit limit', function () {
    $jobs = array_map(fn (int $i): array => ['class' => "App\\Jobs\\Job{$i}"], range(1, 60));
    $artifacts = ['jobs' => $jobs];

    $default = (new ArtifactQueryService)->artifactsOfType($artifacts, 'jobs');
    $limited = (new ArtifactQueryService)->artifactsOfType($artifacts, 'jobs', limit: 2);

    expect($default)->toHaveCount(50)
        ->and($limited)->toBe([['class' => 'App\\Jobs\\Job1'], ['class' => 'App\\Jobs\\Job2']]);
});

// search()

test('search() finds matches across multiple artifact types', function () {
    $artifacts = [
        'form_requests' => [
            ['id' => 'form_requests:App\\Http\\Requests\\StoreIssueRequest', 'class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'validation_rules' => [
            ['class' => 'App\\Rules\\ProjectMember'],
        ],
    ];

    $result = (new ArtifactQueryService)->search($artifacts, 'StoreIssue');

    expect($result)->toBe([
        ['type' => 'form_requests', 'artifact' => ['id' => 'form_requests:App\\Http\\Requests\\StoreIssueRequest', 'class' => 'App\\Http\\Requests\\StoreIssueRequest']],
    ]);
});

test('search() restricts to a single type when a type filter is given', function () {
    $artifacts = [
        'form_requests' => [
            ['class' => 'App\\Http\\Requests\\StoreIssueRequest'],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\StoreIssueAttachment'],
        ],
    ];

    $result = (new ArtifactQueryService)->search($artifacts, 'StoreIssue', typeFilter: 'jobs');

    expect($result)->toBe([
        ['type' => 'jobs', 'artifact' => ['class' => 'App\\Jobs\\StoreIssueAttachment']],
    ]);
});

test('search() ignores unsupported (e.g. legacy) artifact type keys', function () {
    $artifacts = [
        'requests' => [
            ['class' => 'App\\Http\\Requests\\LegacyIssueRequest'],
        ],
    ];

    expect((new ArtifactQueryService)->search($artifacts, 'Legacy'))->toBe([]);
});

test('search() returns an empty list when nothing matches', function () {
    $artifacts = ['jobs' => [['class' => 'App\\Jobs\\NotifyAssignee']]];

    expect((new ArtifactQueryService)->search($artifacts, 'nonexistent'))->toBe([]);
});

// isSupportedType()

test('isSupportedType() recognizes every current artifact type', function () {
    $service = new ArtifactQueryService;

    foreach (ArtifactQueryService::SUPPORTED_TYPES as $type) {
        expect($service->isSupportedType($type))->toBeTrue();
    }
});

test('isSupportedType() rejects unknown or legacy type names', function () {
    expect((new ArtifactQueryService)->isSupportedType('requests'))->toBeFalse()
        ->and((new ArtifactQueryService)->isSupportedType(''))->toBeFalse();
});
