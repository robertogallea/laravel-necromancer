<?php

declare(strict_types=1);

use LaravelNecromancer\Manifest\ConfigurationSummary;
use LaravelNecromancer\Manifest\Inventory;
use LaravelNecromancer\Manifest\StructuralArtifact;

test('routes within the routes type are sorted by method:uri', function () {
    $z = StructuralArtifact::route(name: 'z', method: 'GET', uri: '/z');
    $a = StructuralArtifact::route(name: 'a', method: 'GET', uri: '/a');

    $inventory = new Inventory(
        configuration: ConfigurationSummary::fromArray([]),
        artifacts: [$z, $a],
    );

    $routes = $inventory->toArray()['artifacts']['routes'];

    expect($routes[0]['uri'])->toBe('/a')
        ->and($routes[1]['uri'])->toBe('/z');
});

test('class-based artifacts within a type are sorted by class name', function () {
    $z = StructuralArtifact::model(class: 'App\\Models\\Zebra', table: 'zebras');
    $a = StructuralArtifact::model(class: 'App\\Models\\Aardvark', table: 'aardvarks');

    $inventory = new Inventory(
        configuration: ConfigurationSummary::fromArray([]),
        artifacts: [$z, $a],
    );

    $models = $inventory->toArray()['artifacts']['models'];

    expect($models[0]['class'])->toBe('App\\Models\\Aardvark')
        ->and($models[1]['class'])->toBe('App\\Models\\Zebra');
});

test('artifact type keys are sorted alphabetically', function () {
    $route = StructuralArtifact::route(name: 'home', method: 'GET', uri: '/');
    $model = StructuralArtifact::model(class: 'App\\Models\\User', table: 'users');

    $inventory = new Inventory(
        configuration: ConfigurationSummary::fromArray([]),
        artifacts: [$route, $model],
    );

    $keys = array_keys($inventory->toArray()['artifacts']);

    expect($keys)->toBe(array_values(array_unique(array_merge([], $keys))))
        ->and($keys[0])->toBe('models')
        ->and($keys[1])->toBe('routes');
});
