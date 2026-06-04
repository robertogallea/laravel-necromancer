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
