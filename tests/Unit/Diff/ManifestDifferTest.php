<?php

declare(strict_types=1);

use LaravelNecromancer\Diff\ManifestDiff;
use LaravelNecromancer\Diff\ManifestDiffer;

test('detects added artifact', function () {
    $differ = new ManifestDiffer;
    $base = ['routes' => []];
    $head = ['routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']]];

    $diff = $differ->diff($base, $head);

    expect($diff->added)->toHaveKey('routes')
        ->and($diff->added['routes'])->toHaveCount(1)
        ->and($diff->removed)->toBeEmpty()
        ->and($diff->changed)->toBeEmpty();
});

test('detects removed artifact', function () {
    $differ = new ManifestDiffer;
    $base = ['routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']]];
    $head = ['routes' => []];

    $diff = $differ->diff($base, $head);

    expect($diff->removed)->toHaveKey('routes')
        ->and($diff->removed['routes'])->toHaveCount(1)
        ->and($diff->added)->toBeEmpty()
        ->and($diff->changed)->toBeEmpty();
});

test('detects changed artifact with field diff', function () {
    $differ = new ManifestDiffer;
    $base = ['routes' => [['method' => 'GET', 'uri' => '/orders', 'middleware' => ['web']]]];
    $head = ['routes' => [['method' => 'GET', 'uri' => '/orders', 'middleware' => ['web', 'auth']]]];

    $diff = $differ->diff($base, $head);

    expect($diff->changed)->toHaveKey('routes')
        ->and($diff->changed['routes'])->toHaveCount(1)
        ->and($diff->changed['routes'][0])->toHaveKeys(['from', 'to']);
});

test('does not flag identical artifacts', function () {
    $differ = new ManifestDiffer;
    $artifact = ['method' => 'GET', 'uri' => '/orders', 'middleware' => ['web']];

    $diff = $differ->diff(['routes' => [$artifact]], ['routes' => [$artifact]]);

    expect($diff->isEmpty())->toBeTrue();
});

test('uses method:uri as canonical key for routes', function () {
    $differ = new ManifestDiffer;
    $base = ['routes' => [['method' => 'GET', 'uri' => '/orders']]];
    $head = ['routes' => [['method' => 'POST', 'uri' => '/orders']]];

    $diff = $differ->diff($base, $head);

    expect($diff->totalAdditions())->toBe(1)
        ->and($diff->totalRemovals())->toBe(1);
});

test('uses class as canonical key for non-route artifacts', function () {
    $differ = new ManifestDiffer;
    $base = ['models' => [['class' => 'App\\Models\\Order']]];
    $head = ['models' => [['class' => 'App\\Models\\Order', 'fillable' => ['name']]]];

    $diff = $differ->diff($base, $head);

    expect($diff->changed)->toHaveKey('models')
        ->and($diff->added)->toBeEmpty()
        ->and($diff->removed)->toBeEmpty();
});

test('handles empty manifests', function () {
    $differ = new ManifestDiffer;

    $diff = $differ->diff([], []);

    expect($diff->isEmpty())->toBeTrue();
});

test('handles artifacts across multiple types', function () {
    $differ = new ManifestDiffer;
    $base = [
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']],
        'models' => [['class' => 'App\\Models\\Order', 'fillable' => ['name']]],
    ];
    $head = [
        'routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'middleware' => ['auth']]],
        'events' => [['class' => 'App\\Events\\OrderPlaced']],
    ];

    $diff = $differ->diff($base, $head);

    expect($diff->changed)->toHaveKey('routes')
        ->and($diff->added)->toHaveKey('events')
        ->and($diff->removed)->toHaveKey('models');
});

test('throws on artifact with no canonical key', function () {
    $differ = new ManifestDiffer;

    expect(fn () => $differ->diff(['models' => [['name' => 'no-class-field']]], []))
        ->toThrow(InvalidArgumentException::class);
});

test('uses file as canonical key for tests artifacts', function () {
    $differ = new ManifestDiffer;
    $artifact = [
        'file' => 'tests/Feature/Auth/AuthenticationTest.php',
        'type' => 'feature',
        'subject' => 'App\\Auth\\Authentication',
        'methods' => ['login screen can be rendered'],
        'source' => ['file' => 'tests/Feature/Auth/AuthenticationTest.php', 'line' => 1, 'hash' => 'abc123'],
    ];

    $base = ['tests' => [$artifact]];
    $head = ['tests' => [array_merge($artifact, ['methods' => ['login screen can be rendered', 'users can logout']])]];

    $diff = $differ->diff($base, $head);

    expect($diff->changed)->toHaveKey('tests')
        ->and($diff->added)->toBeEmpty()
        ->and($diff->removed)->toBeEmpty();
});

test('isEmpty returns false when there are additions', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [['method' => 'GET', 'uri' => '/orders']]],
        removed: [],
        changed: [],
    );

    expect($diff->isEmpty())->toBeFalse();
});

test('totalAdditions sums across all types', function () {
    $diff = new ManifestDiff(
        added: [
            'routes' => [['method' => 'GET', 'uri' => '/orders'], ['method' => 'POST', 'uri' => '/orders']],
            'models' => [['class' => 'App\\Models\\Order']],
        ],
        removed: [],
        changed: [],
    );

    expect($diff->totalAdditions())->toBe(3);
});

it('isEmpty returns false when there are removals', function () {
    $diff = new ManifestDiff(added: [], removed: ['routes' => [['method' => 'GET', 'uri' => '/old']]], changed: []);

    expect($diff->isEmpty())->toBeFalse();
});

it('isEmpty returns false when there are changes', function () {
    $diff = new ManifestDiff(added: [], removed: [], changed: ['routes' => [['from' => [], 'to' => []]]]);

    expect($diff->isEmpty())->toBeFalse();
});

it('totalRemovals sums across all types', function () {
    $diff = new ManifestDiff(
        added: [],
        removed: ['routes' => [['method' => 'GET', 'uri' => '/a'], ['method' => 'GET', 'uri' => '/b']], 'models' => [['class' => 'Foo']]],
        changed: [],
    );

    expect($diff->totalRemovals())->toBe(3);
});

it('totalChanges sums across all types', function () {
    $diff = new ManifestDiff(
        added: [],
        removed: [],
        changed: ['routes' => [['from' => [], 'to' => []]], 'models' => [['from' => [], 'to' => []]]],
    );

    expect($diff->totalChanges())->toBe(2);
});
