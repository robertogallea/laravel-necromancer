<?php

use Illuminate\Routing\Router;
use LaravelNecromancer\Collection\RouteCollector;
use LaravelNecromancer\Manifest\StructuralArtifact;
use LaravelNecromancer\Tests\Fixtures\NecromancerFakeMetadataRoute;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('route-metadata');

test('RoutePayload route_metadata field is omitted when empty', function () {
    $artifact = StructuralArtifact::route(
        name: 'orders.index',
        method: 'GET',
        uri: '/orders',
    );

    expect($artifact->jsonSerialize())->not->toHaveKey('route_metadata');
});

test('RoutePayload serializes raw and necromancer route_metadata', function () {
    $artifact = StructuralArtifact::route(
        name: 'billing.cancel',
        method: 'POST',
        uri: '/billing/cancel',
        metadata: ['head' => ['title' => 'Cancel']],
        necromancerMetadata: ['domain' => 'billing', 'risk' => 'high'],
    );

    $data = $artifact->jsonSerialize();

    expect($data)->toHaveKey('route_metadata')
        ->and($data['route_metadata']['raw'])->toBe(['head' => ['title' => 'Cancel']])
        ->and($data['route_metadata']['necromancer'])->toBe(['domain' => 'billing', 'risk' => 'high']);
});

test('RouteCollector omits route_metadata on Laravel versions without Route::getMetadata()', function () {
    app(Router::class)
        ->get('/fixture-no-metadata', fn () => 'ok')
        ->name('fixture.no-metadata');

    $collector = new RouteCollector(app(Router::class));

    $artifacts = array_values(array_filter(
        $collector->collect(),
        fn ($a) => $a->jsonSerialize()['name'] === 'fixture.no-metadata'
    ));

    expect($artifacts)->not->toBeEmpty();
    expect($artifacts[0]->jsonSerialize())->not->toHaveKey('route_metadata');
});

test('RouteCollector extracts and normalizes route metadata when Route::getMetadata() is available', function () {
    $route = new NecromancerFakeMetadataRoute(['GET'], '/fixture-billing-cancel', ['uses' => fn () => 'ok']);
    $route->name('fixture.billing.cancel');
    $route->metadata([
        'head' => ['title' => 'Cancel subscription'],
        'necromancer' => [
            'domain' => 'billing',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adr' => 'docs/adr/001.md',
            'adrs' => ['docs/adr/002.md', 'docs/adr/001.md'],
        ],
    ]);

    app(Router::class)->getRoutes()->add($route);

    $collector = new RouteCollector(app(Router::class));

    $artifacts = array_values(array_filter(
        $collector->collect(),
        fn ($a) => $a->jsonSerialize()['name'] === 'fixture.billing.cancel'
    ));

    expect($artifacts)->not->toBeEmpty();
    $data = $artifacts[0]->jsonSerialize();

    expect($data)->toHaveKey('route_metadata')
        ->and($data['route_metadata']['raw']['head']['title'])->toBe('Cancel subscription')
        ->and($data['route_metadata']['necromancer'])->toBe([
            'domain' => 'billing',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adr' => 'docs/adr/001.md',
            'adrs' => ['docs/adr/001.md', 'docs/adr/002.md'],
        ])
        ->and($data['annotations'])->toBe([
            'domain' => 'billing',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adrs' => ['docs/adr/001.md', 'docs/adr/002.md'],
        ]);
});

test('RouteCollector omits the necromancer key when the namespace is absent from raw metadata', function () {
    $route = new NecromancerFakeMetadataRoute(['GET'], '/fixture-seo-only', ['uses' => fn () => 'ok']);
    $route->name('fixture.seo-only');
    $route->metadata(['head' => ['title' => 'SEO only, no necromancer namespace']]);

    app(Router::class)->getRoutes()->add($route);

    $collector = new RouteCollector(app(Router::class));

    $artifacts = array_values(array_filter(
        $collector->collect(),
        fn ($a) => $a->jsonSerialize()['name'] === 'fixture.seo-only'
    ));

    expect($artifacts)->not->toBeEmpty();
    $data = $artifacts[0]->jsonSerialize();

    expect($data['route_metadata'])->toHaveKey('raw')
        ->and($data['route_metadata'])->not->toHaveKey('necromancer');
});
