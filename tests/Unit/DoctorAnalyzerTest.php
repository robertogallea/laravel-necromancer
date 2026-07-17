<?php

use LaravelNecromancer\Doctor\DimensionResult;
use LaravelNecromancer\Doctor\DoctorAnalyzer;

function routeMetadataDimension(array $artifacts): DimensionResult
{
    $analyzer = new DoctorAnalyzer($artifacts);

    foreach ($analyzer->dimensions() as $dimension) {
        if ($dimension->key === 'route-metadata-coverage') {
            return $dimension;
        }
    }

    throw new RuntimeException('route-metadata-coverage dimension not found');
}

test('dimensions() includes route-metadata-coverage as an 8th dimension', function () {
    $analyzer = new DoctorAnalyzer(['routes' => []]);

    expect($analyzer->dimensions())->toHaveCount(8);
});

test('route-metadata-coverage scores N/A when no route declares necromancer metadata', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/orders'],
            ['method' => 'POST', 'uri' => '/orders', 'route_metadata' => ['raw' => ['head' => ['title' => 'x']]]],
        ],
    ]);

    expect($dimension->score)->toBe(1.0)
        ->and($dimension->detail)->toBe('N/A');
});

test('route-metadata-coverage scores domain coverage among annotated routes', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'route_metadata' => ['necromancer' => ['domain' => 'billing']]],
            ['method' => 'GET', 'uri' => '/b', 'route_metadata' => ['necromancer' => ['risk' => 'low']]],
        ],
    ]);

    expect($dimension->score)->toBe(0.5)
        ->and($dimension->detail)->toContain('1/2 tagged with domain');
});

test('route-metadata-coverage penalizes high-risk routes missing an ADR reference', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'risk' => 'high', 'adr' => 'docs/adr/1.md']]],
            ['method' => 'GET', 'uri' => '/b', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'risk' => 'critical']]],
        ],
    ]);

    expect($dimension->detail)->toContain('1/2 high-risk with ADR');
});

test('route-metadata-coverage penalizes external-service routes without matching test evidence', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            [
                'method' => 'GET', 'uri' => '/a', 'controller' => 'App\\Http\\Controllers\\StripeController',
                'route_metadata' => ['necromancer' => ['domain' => 'billing', 'external_services' => ['stripe']]],
            ],
            [
                'method' => 'GET', 'uri' => '/b', 'controller' => 'App\\Http\\Controllers\\SlackController',
                'route_metadata' => ['necromancer' => ['domain' => 'billing', 'external_services' => ['slack']]],
            ],
        ],
        'tests' => [
            ['subject' => 'App\\Http\\Controllers\\StripeController'],
        ],
    ]);

    expect($dimension->detail)->toContain('1/2 external-service routes tested');
});

test('route-metadata-coverage scores full flow consistency when a flow group agrees on domain and risk', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']]],
            ['method' => 'GET', 'uri' => '/b', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']]],
        ],
    ]);

    expect($dimension->detail)->toContain('2/2 flow-consistent');
});

test('route-metadata-coverage penalizes a flow group with conflicting risk levels', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']]],
            ['method' => 'GET', 'uri' => '/b', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'low']]],
        ],
    ]);

    expect($dimension->detail)->toContain('0/2 flow-consistent');
});

test('route-metadata-coverage does not mention flow consistency when no flow has multiple routes', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'flow' => 'cancel']]],
            ['method' => 'GET', 'uri' => '/b', 'route_metadata' => ['necromancer' => ['domain' => 'billing', 'flow' => 'refund']]],
        ],
    ]);

    expect($dimension->detail)->not->toContain('flow-consistent');
});

test('route-metadata-coverage weight is 0.10', function () {
    $dimension = routeMetadataDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'route_metadata' => ['necromancer' => ['domain' => 'billing']]],
        ],
    ]);

    expect($dimension->weight)->toBe(0.10);
});
