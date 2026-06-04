<?php

use Illuminate\Routing\Router;
use LaravelNecromancer\Collection\RouteCollector;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Tests\Fixtures\Controllers\OrderController;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('route-authorization');

test('RoutePayload authorization field is omitted when empty', function () {
    $artifact = StructuralArtifact::route(
        name: 'orders.index',
        method: 'GET',
        uri: '/orders',
    );
    expect($artifact->jsonSerialize())->not->toHaveKey('authorization');
});

test('RoutePayload serializes authorization as structured list', function () {
    $artifact = StructuralArtifact::route(
        name: 'orders.update',
        method: 'PUT',
        uri: '/orders/{id}',
        authorization: [
            ['ability' => 'manage-billing', 'models' => ['App\\Models\\User']],
        ],
    );
    $data = $artifact->jsonSerialize();
    expect($data['authorization'])->toHaveCount(1)
        ->and($data['authorization'][0]['ability'])->toBe('manage-billing')
        ->and($data['authorization'][0]['models'])->toContain('App\\Models\\User');
});

test('RouteCollector reads class-level #[Authorize] and adds authorization field', function () {
    app(Router::class)
        ->get('/fixture-orders', [OrderController::class, 'index'])
        ->name('fixture.orders.index');

    $collector = new RouteCollector(
        app(Router::class)
    );

    $artifacts = array_values(array_filter(
        $collector->collect(),
        fn ($a) => $a->jsonSerialize()['name'] === 'fixture.orders.index'
    ));

    expect($artifacts)->not->toBeEmpty();
    $data = $artifacts[0]->jsonSerialize();
    expect($data)->toHaveKey('authorization')
        ->and($data['authorization'][0]['ability'])->toBe('view-orders');
});

test('RouteCollector skips class-level #[Authorize] when action is excluded by only filter', function () {
    app(Router::class)
        ->put('/fixture-orders/{id}', [OrderController::class, 'update'])
        ->name('fixture.orders.update');

    $collector = new RouteCollector(
        app(Router::class)
    );

    $artifacts = array_values(array_filter(
        $collector->collect(),
        fn ($a) => $a->jsonSerialize()['name'] === 'fixture.orders.update'
    ));

    expect($artifacts)->not->toBeEmpty();
    $data = $artifacts[0]->jsonSerialize();
    // The class-level #[Authorize('view-orders', only: ['index'])] should NOT appear for 'update'
    // But the method-level #[Authorize('manage-billing', User::class)] SHOULD appear
    $abilities = array_column($data['authorization'] ?? [], 'ability');
    expect($abilities)->not->toContain('view-orders')
        ->and($abilities)->toContain('manage-billing');
});

test('RouteCollector includes class-level #[Authorize] when action matches only filter', function () {
    app(Router::class)
        ->get('/fixture-orders-v2', [OrderController::class, 'index'])
        ->name('fixture.orders.index.v2');

    $collector = new RouteCollector(
        app(Router::class)
    );

    $artifacts = array_values(array_filter(
        $collector->collect(),
        fn ($a) => $a->jsonSerialize()['name'] === 'fixture.orders.index.v2'
    ));

    expect($artifacts)->not->toBeEmpty();
    $data = $artifacts[0]->jsonSerialize();
    $abilities = array_column($data['authorization'] ?? [], 'ability');
    expect($abilities)->toContain('view-orders');
});
