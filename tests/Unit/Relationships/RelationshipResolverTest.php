<?php

use LaravelNecromancer\Relationships\RelationshipEdge;
use LaravelNecromancer\Relationships\RelationshipResolver;

test('resolve() returns a controller edge for routes', function () {
    $edges = (new RelationshipResolver)->resolve('routes', ['controller' => 'App\\Http\\Controllers\\OrderController']);

    expect($edges)->toEqual([
        new RelationshipEdge('controller', ['App\\Http\\Controllers\\OrderController']),
    ]);
});

test('resolve() omits the controller edge for routes with no controller', function () {
    expect((new RelationshipResolver)->resolve('routes', []))->toBe([])
        ->and((new RelationshipResolver)->resolve('routes', ['controller' => '']))->toBe([]);
});

test('resolve() returns model relationship, policy, and observer edges in declaration order', function () {
    $facts = [
        'relationships' => [
            ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => 'customer'],
            ['type' => 'belongsTo', 'related' => 'App\\Models\\Unknown', 'method' => 'unknown'],
        ],
        'policy' => 'App\\Policies\\OrderPolicy',
        'observers' => ['App\\Observers\\OrderObserver'],
    ];

    $edges = (new RelationshipResolver)->resolve('models', $facts);

    expect($edges)->toEqual([
        new RelationshipEdge('customer', ['App\\Models\\Customer'], 'belongsTo'),
        new RelationshipEdge('unknown', ['App\\Models\\Unknown'], 'belongsTo'),
        new RelationshipEdge('policy', ['App\\Policies\\OrderPolicy']),
        new RelationshipEdge('observers', ['App\\Observers\\OrderObserver']),
    ]);
});

test('resolve() skips model relationships missing a method or a string related target', function () {
    $facts = [
        'relationships' => [
            ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => ''],
            ['type' => 'belongsTo', 'related' => null, 'method' => 'broken'],
            'not-an-array',
        ],
    ];

    expect((new RelationshipResolver)->resolve('models', $facts))->toBe([]);
});

test('resolve() returns a listeners edge for events', function () {
    $edges = (new RelationshipResolver)->resolve('events', ['listeners' => ['App\\Listeners\\SendOrderConfirmation']]);

    expect($edges)->toEqual([
        new RelationshipEdge('listeners', ['App\\Listeners\\SendOrderConfirmation']),
    ]);
});

test('resolve() returns a handles edge for listeners', function () {
    $edges = (new RelationshipResolver)->resolve('listeners', ['handles' => ['App\\Events\\OrderPlaced']]);

    expect($edges)->toEqual([
        new RelationshipEdge('handles', ['App\\Events\\OrderPlaced']),
    ]);
});

test('resolve() returns a model edge for policies and observers', function () {
    expect((new RelationshipResolver)->resolve('policies', ['model' => 'App\\Models\\Order']))->toEqual([
        new RelationshipEdge('model', ['App\\Models\\Order']),
    ])->and((new RelationshipResolver)->resolve('observers', ['model' => 'App\\Models\\Order']))->toEqual([
        new RelationshipEdge('model', ['App\\Models\\Order']),
    ]);
});

test('resolve() filters non-string and empty values out of list-valued edges', function () {
    $edges = (new RelationshipResolver)->resolve('events', ['listeners' => ['App\\Listeners\\A', '', null, 123]]);

    expect($edges)->toEqual([
        new RelationshipEdge('listeners', ['App\\Listeners\\A']),
    ]);
});

test('resolve() omits a list-valued edge entirely when every value is filtered out', function () {
    expect((new RelationshipResolver)->resolve('events', ['listeners' => ['', null]]))->toBe([]);
});

test('resolve() returns no edges for artifact types with no relationship taxonomy', function () {
    expect((new RelationshipResolver)->resolve('jobs', ['queue' => 'emails']))->toBe([])
        ->and((new RelationshipResolver)->resolve('gates', ['ability' => 'edit-post']))->toBe([]);
});
