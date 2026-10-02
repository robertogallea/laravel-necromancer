<?php

use LaravelNecromancer\Benchmark\GoldenAnswerResolver;

function resolverManifest(): array
{
    return [
        'artifacts' => [
            'routes' => [
                ['name' => 'projects.index', 'method' => 'GET',  'uri' => '/projects', 'middleware' => ['auth']],
                ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects', 'middleware' => ['auth']],
                ['name' => null,             'method' => 'GET',  'uri' => '/about',    'middleware' => []],
                ['name' => 'welcome',        'method' => 'GET',  'uri' => '/',         'middleware' => []],
            ],
            'models' => [
                ['class' => 'App\\Models\\Project', 'casts' => ['id' => 'int', 'visibility' => 'App\\Enums\\ProjectVisibility'], 'observers' => ['App\\Observers\\ProjectObserver']],
            ],
            'jobs' => [
                ['class' => 'App\\Jobs\\CloseIssues', 'queue' => 'default', 'tries' => 3],
            ],
            'events' => [
                ['class' => 'App\\Events\\IssueOpened'],
                ['class' => 'App\\Events\\IssueClosed'],
            ],
            'policies' => [
                ['class' => 'App\\Policies\\IssuePolicy',   'model' => 'App\\Models\\Issue'],
                ['class' => 'App\\Policies\\ProjectPolicy', 'model' => 'App\\Models\\Project'],
            ],
        ],
    ];
}

test('resolves routes.named to list of non-null route names', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['routes.named']);

    expect($result['routes.named']['value'])->toBe(['projects.index', 'projects.store', 'welcome']);
});

test('resolves models.casts.Project to cast array', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.casts.Project']);

    expect($result['models.casts.Project']['value'])->toBe(['id' => 'int', 'visibility' => 'App\\Enums\\ProjectVisibility']);
});

test('resolves models.observers.Project to observer list', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.observers.Project']);

    expect($result['models.observers.Project']['value'])->toBe(['App\\Observers\\ProjectObserver']);
});

test('resolves models.observer_short_names.Project to short class names', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.observer_short_names.Project']);

    expect($result['models.observer_short_names.Project']['value'])->toBe(['ProjectObserver']);
});

test('models.observer_short_names returns null when model not found', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.observer_short_names.NonExistent']);

    expect($result['models.observer_short_names.NonExistent']['value'])->toBeNull();
});

test('models.observer_short_names returns empty array when model has no observers', function () {
    $manifest = ['artifacts' => ['models' => [
        ['class' => 'App\\Models\\Label', 'observers' => []],
    ]]];
    $resolver = new GoldenAnswerResolver($manifest);

    $result = $resolver->resolve(['models.observer_short_names.Label']);

    expect($result['models.observer_short_names.Label']['value'])->toBe([]);
});

test('resolves jobs.queue.CloseIssues to queue name', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['jobs.queue.CloseIssues']);

    expect($result['jobs.queue.CloseIssues']['value'])->toBe('default');
});

test('returns null value for an unknown fact_key', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['unknown.key']);

    expect($result['unknown.key']['value'])->toBeNull();
});

test('resolve returns empty array for empty fact_keys', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    expect($resolver->resolve([]))->toBe([]);
});

test('resolves routes.auth_required to route names with auth middleware only', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['routes.auth_required']);

    expect($result['routes.auth_required']['value'])->toBe(['projects.index', 'projects.store']);
});

test('routes.auth_required includes routes with aliased middleware like auth:sanctum', function () {
    $manifest = ['artifacts' => ['routes' => [
        ['name' => 'api.issues', 'method' => 'GET', 'uri' => '/api/issues', 'middleware' => ['auth:sanctum']],
        ['name' => 'public',     'method' => 'GET', 'uri' => '/public',     'middleware' => ['web']],
    ]]];
    $resolver = new GoldenAnswerResolver($manifest);

    $result = $resolver->resolve(['routes.auth_required']);

    expect($result['routes.auth_required']['value'])->toBe(['api.issues']);
});

test('resolves policies.models to model short names with a policy', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['policies.models']);

    expect($result['policies.models']['value'])->toBe(['Issue', 'Project']);
});

test('resolves events.named to event short names', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['events.named']);

    expect($result['events.named']['value'])->toBe(['IssueOpened', 'IssueClosed']);
});

test('resolves jobs.named to job short names', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['jobs.named']);

    expect($result['jobs.named']['value'])->toBe(['CloseIssues']);
});

test('resolves models.cast_keys.Project to cast field names as a flat list', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.cast_keys.Project']);

    expect($result['models.cast_keys.Project']['value'])->toBe(['id', 'visibility']);
});

test('models.cast_keys returns null when model not found', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.cast_keys.NonExistent']);

    expect($result['models.cast_keys.NonExistent']['value'])->toBeNull();
});

test('models.cast_keys returns empty array when model exists but has no casts', function () {
    $manifest = ['artifacts' => ['models' => [
        ['class' => 'App\\Models\\Label', 'casts' => []],
    ]]];
    $resolver = new GoldenAnswerResolver($manifest);

    $result = $resolver->resolve(['models.cast_keys.Label']);

    expect($result['models.cast_keys.Label']['value'])->toBe([]);
});

/**
 * Events and listeners linked on either side (an event's `listeners`, a
 * listener's `handles`), one listener handling two events, and an event
 * listened to by a class Necromancer did not collect.
 */
function relationshipResolverManifest(): array
{
    return [
        'artifacts' => [
            'events' => [
                ['id' => 'events:App\\Events\\IssueOpened', 'class' => 'App\\Events\\IssueOpened', 'listeners' => ['App\\Listeners\\NotifyWatchers']],
                ['id' => 'events:App\\Events\\IssueClosed', 'class' => 'App\\Events\\IssueClosed', 'listeners' => ['App\\Listeners\\NotifyWatchers', 'Vendor\\Audit\\RecordEvent']],
                ['id' => 'events:App\\Events\\IssueArchived', 'class' => 'App\\Events\\IssueArchived'],
            ],
            'listeners' => [
                ['id' => 'listeners:App\\Listeners\\NotifyWatchers', 'class' => 'App\\Listeners\\NotifyWatchers', 'handles' => ['App\\Events\\IssueOpened', 'App\\Events\\IssueClosed']],
                ['id' => 'listeners:App\\Listeners\\CloseMilestone', 'class' => 'App\\Listeners\\CloseMilestone', 'handles' => ['App\\Events\\IssueClosed']],
            ],
        ],
    ];
}

test('resolves events.listeners to the short names of every listener a listened_by Relationship reaches, once each', function () {
    $result = (new GoldenAnswerResolver(relationshipResolverManifest()))->resolve(['events.listeners']);

    expect($result['events.listeners']['value'])->toBe(['NotifyWatchers', 'RecordEvent', 'CloseMilestone']);
});

test('events.listeners resolves to an empty list when no event has a listener', function () {
    $manifest = ['artifacts' => ['events' => [['id' => 'events:App\\Events\\IssueOpened', 'class' => 'App\\Events\\IssueOpened']]]];

    expect((new GoldenAnswerResolver($manifest))->resolve(['events.listeners'])['events.listeners']['value'])->toBe([]);
});

test('resolves dispatches.targets to the short names of every class a dispatches Relationship reaches, once each', function () {
    $manifest = ['artifacts' => [
        'controllers' => [[
            'id' => 'controllers:App\\Http\\Controllers\\IssueController',
            'class' => 'App\\Http\\Controllers\\IssueController',
            'dispatches' => [
                ['target' => 'App\\Events\\IssueOpened', 'method' => 'store', 'mode' => null],
                ['target' => 'App\\Jobs\\IndexIssue', 'method' => 'store', 'mode' => 'queued'],
            ],
        ]],
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\IndexIssue',
                'class' => 'App\\Jobs\\IndexIssue',
                'dispatches' => [['target' => 'App\\Mail\\IssueIndexed', 'method' => 'handle', 'mode' => 'sync']],
            ],
            [
                'id' => 'jobs:App\\Jobs\\ReindexAll',
                'class' => 'App\\Jobs\\ReindexAll',
                'dispatches' => [['target' => 'App\\Jobs\\IndexIssue', 'method' => 'handle', 'mode' => 'queued']],
            ],
        ],
        'events' => [['id' => 'events:App\\Events\\IssueOpened', 'class' => 'App\\Events\\IssueOpened']],
    ]];

    $result = (new GoldenAnswerResolver($manifest))->resolve(['dispatches.targets']);

    expect($result['dispatches.targets']['value'])->toBe(['IssueOpened', 'IndexIssue', 'IssueIndexed'])
        ->and($result['dispatches.targets']['trusted'])->toBeTrue();
});

test('dispatches.targets resolves to an empty list when nothing dispatches', function () {
    expect((new GoldenAnswerResolver(resolverManifest()))->resolve(['dispatches.targets'])['dispatches.targets']['value'])->toBe([]);
});

/**
 * Order relates to User, is authorized by OrderPolicy, and belongs to the
 * checkout flow; a route reaches Order only through the policy.
 */
function impactResolverManifest(): array
{
    return ['artifacts' => [
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
                'relationships' => [['type' => 'belongsTo', 'related' => 'App\\Models\\User', 'method' => 'author']],
                'annotations' => ['flow' => 'checkout'],
            ],
            ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User'],
        ],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order']],
    ]];
}

test('resolves impact.labels.<Artifact ID> to the label of every node the Impact reaches at depth 1', function () {
    $result = (new GoldenAnswerResolver(impactResolverManifest()))->resolve(['impact.labels.models:App\\Models\\Order']);

    expect($result['impact.labels.models:App\\Models\\Order']['value'])
        ->toEqualCanonicalizing(['App\\Models\\User', 'App\\Policies\\OrderPolicy', 'flow:checkout'])
        ->and($result['impact.labels.models:App\\Models\\Order']['trusted'])->toBeTrue();
});

test('impact.labels resolves to null for an Artifact ID that is not in the manifest', function () {
    $result = (new GoldenAnswerResolver(impactResolverManifest()))->resolve(['impact.labels.models:App\\Models\\Nope']);

    expect($result['impact.labels.models:App\\Models\\Nope']['value'])->toBeNull();
});
