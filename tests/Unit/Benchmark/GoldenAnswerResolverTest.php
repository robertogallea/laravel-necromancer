<?php

use LaravelNecromancer\Benchmark\GoldenAnswerResolver;

function resolverManifest(): array
{
    return [
        'artifacts' => [
            'routes' => [
                ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects', 'middleware' => ['auth']],
                ['name' => 'projects.store', 'method' => 'POST', 'uri' => '/projects', 'middleware' => ['auth']],
                ['name' => null,             'method' => 'GET', 'uri' => '/about',    'middleware' => []],
            ],
            'models' => [
                ['class' => 'App\\Models\\Project', 'casts' => ['metadata' => 'array'], 'observers' => ['App\\Observers\\ProjectObserver']],
            ],
            'jobs' => [
                ['class' => 'App\\Jobs\\CloseIssues', 'queue' => 'default', 'tries' => 3],
            ],
        ],
    ];
}

test('resolves routes.named to list of non-null route names', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['routes.named']);

    expect($result['routes.named']['value'])->toBe(['projects.index', 'projects.store']);
});

test('resolves models.casts.Project to cast array', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.casts.Project']);

    expect($result['models.casts.Project']['value'])->toBe(['metadata' => 'array']);
});

test('resolves models.observers.Project to observer list', function () {
    $resolver = new GoldenAnswerResolver(resolverManifest());

    $result = $resolver->resolve(['models.observers.Project']);

    expect($result['models.observers.Project']['value'])->toBe(['App\\Observers\\ProjectObserver']);
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
