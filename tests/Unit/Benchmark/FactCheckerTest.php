<?php

use LaravelNecromancer\Benchmark\FactChecker;

test('accuracy is 1.0 when all must_contain strings are found', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        'The route projects.index requires auth middleware.',
        ['must_contain' => ['projects.index', 'auth'], 'must_not_contain' => []]
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('accuracy is 0.5 when half of must_contain strings are found', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        'The route projects.index is defined.',
        ['must_contain' => ['projects.index', 'auth'], 'must_not_contain' => []]
    );

    expect($result['accuracy'])->toBe(0.5);
});

test('accuracy is 1.0 when must_contain is empty', function () {
    $checker = new FactChecker;

    $result = $checker->check('anything', ['must_contain' => [], 'must_not_contain' => []]);

    expect($result['accuracy'])->toBe(1.0);
});

test('hallucinationRate is 1.0 when all must_not_contain strings appear in response', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        "Route::get('/projects', ...) and Route::get('/fake-route', ...)",
        ['must_contain' => [], 'must_not_contain' => ["Route::get('/projects'", "Route::get('/fake-route'"]]
    );

    expect($result['hallucinationRate'])->toBe(1.0);
});

test('hallucinationRate is 0.0 when no must_not_contain strings appear', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        'Route::resource("projects", ProjectController::class);',
        ['must_contain' => [], 'must_not_contain' => ["Route::get('/projects'"]]
    );

    expect($result['hallucinationRate'])->toBe(0.0);
});

test('hallucinationRate is 0.0 when must_not_contain is empty', function () {
    $checker = new FactChecker;

    $result = $checker->check('anything', ['must_contain' => [], 'must_not_contain' => []]);

    expect($result['hallucinationRate'])->toBe(0.0);
});

test('must_contain matching is case-insensitive', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        'The route Projects.Index requires Auth middleware.',
        ['must_contain' => ['projects.index', 'auth'], 'must_not_contain' => []]
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('must_not_contain matching is case-insensitive', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        'There are No Jobs in this application.',
        ['must_contain' => [], 'must_not_contain' => ['no jobs']]
    );

    expect($result['hallucinationRate'])->toBe(1.0);
});

test('must_recall_from computes recall against a resolved list', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => ['projects.index', 'issues.show', 'profile.edit'], 'trusted' => true]];

    $result = $checker->check(
        'The routes that require auth are: projects.index and issues.show.',
        ['must_recall_from' => 'routes.auth_required', 'must_not_contain' => []],
        $resolved
    );

    expect($result['accuracy'])->toBe(2 / 3);
});

test('must_recall_from yields accuracy 1.0 when all items are mentioned', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => ['projects.index', 'issues.show'], 'trusted' => true]];

    $result = $checker->check(
        'Auth routes: projects.index, issues.show.',
        ['must_recall_from' => 'routes.auth_required', 'must_not_contain' => []],
        $resolved
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('must_recall_from is case-insensitive', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => ['projects.index'], 'trusted' => true]];

    $result = $checker->check(
        'The route Projects.Index requires auth.',
        ['must_recall_from' => 'routes.auth_required', 'must_not_contain' => []],
        $resolved
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('must_recall_from yields accuracy 1.0 when resolved value is null', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => null, 'trusted' => false]];

    $result = $checker->check(
        'anything',
        ['must_recall_from' => 'routes.auth_required', 'must_not_contain' => []],
        $resolved
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('must_recall_from yields accuracy 1.0 when resolved list is empty', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => [], 'trusted' => true]];

    $result = $checker->check(
        'anything',
        ['must_recall_from' => 'routes.auth_required', 'must_not_contain' => []],
        $resolved
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('must_recall_from falls back to must_contain when resolved key is absent', function () {
    $checker = new FactChecker;

    $result = $checker->check(
        'The route projects.index requires auth.',
        ['must_recall_from' => 'routes.auth_required', 'must_contain' => ['auth'], 'must_not_contain' => []],
        []
    );

    expect($result['accuracy'])->toBe(1.0);
});

test('accuracy is average of recall and keyword rates when both must_recall_from and must_contain are present and resolved', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => ['projects.index', 'issues.show'], 'trusted' => true]];

    // recall: 2/2 = 1.0 (both items found); keywords: 1/2 = 0.5 (only FormRequest found) → average = 0.75
    $result = $checker->check(
        'The routes projects.index and issues.show need auth. Use FormRequest.',
        [
            'must_recall_from' => 'routes.auth_required',
            'must_contain' => ['FormRequest', 'ShouldQueue'],
            'must_not_contain' => [],
        ],
        $resolved
    );

    expect($result['accuracy'])->toBe(0.75);
});

test('accuracy uses only recall when must_contain is empty alongside must_recall_from', function () {
    $checker = new FactChecker;
    $resolved = ['routes.auth_required' => ['value' => ['projects.index'], 'trusted' => true]];

    $result = $checker->check(
        'projects.index requires auth.',
        ['must_recall_from' => 'routes.auth_required', 'must_contain' => [], 'must_not_contain' => []],
        $resolved
    );

    expect($result['accuracy'])->toBe(1.0);
});
