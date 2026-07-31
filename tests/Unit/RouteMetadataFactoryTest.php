<?php

use Illuminate\Routing\Router;
use LaravelNecromancer\Facades\Necromancer;
use LaravelNecromancer\Tests\Fixtures\NecromancerFakeMetadataRoute;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('route-metadata');

test('forMetadata returns only the supplied fields under the necromancer namespace', function () {
    $result = Necromancer::forMetadata(
        domain: 'billing',
        flow: 'subscription-cancellation',
        capability: 'subscription.cancel',
        summary: 'Cancels an active subscription.',
        risk: 'high',
        externalServices: ['stripe'],
        adr: 'docs/adr/004-subscription-cancellation.md',
    );

    expect($result)->toBe([
        'necromancer' => [
            'domain' => 'billing',
            'flow' => 'subscription-cancellation',
            'capability' => 'subscription.cancel',
            'summary' => 'Cancels an active subscription.',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adr' => 'docs/adr/004-subscription-cancellation.md',
        ],
    ]);
});

test('forMetadata called with no arguments returns an empty array', function () {
    expect(Necromancer::forMetadata())->toBe([]);
});

test('forMetadata wraps a single external service string into a list', function () {
    $result = Necromancer::forMetadata(externalServices: 'stripe');

    expect($result)->toBe(['necromancer' => ['external_services' => ['stripe']]]);
});

test('forMetadata passes an external services array through unchanged', function () {
    $result = Necromancer::forMetadata(externalServices: ['stripe', 'sendgrid']);

    expect($result)->toBe(['necromancer' => ['external_services' => ['stripe', 'sendgrid']]]);
});

test('forMetadata respects a custom route_metadata namespace', function () {
    config(['necromancer.route_metadata.namespace' => 'acme']);

    $result = Necromancer::forMetadata(domain: 'billing');

    expect($result)->toBe(['acme' => ['domain' => 'billing']]);
});

test('forMetadata output attaches to a route via the native metadata() method', function () {
    $route = new NecromancerFakeMetadataRoute(['GET'], '/fixture-facade-metadata', ['uses' => fn () => 'ok']);
    $route->name('fixture.facade-metadata');
    $route->metadata(Necromancer::forMetadata(domain: 'billing', risk: 'high'));

    app(Router::class)->getRoutes()->add($route);

    expect($route->getMetadata('necromancer.domain'))->toBe('billing')
        ->and($route->getMetadata('necromancer.risk'))->toBe('high');
});
