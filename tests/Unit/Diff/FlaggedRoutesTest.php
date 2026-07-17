<?php

use LaravelNecromancer\Diff\FlaggedRoutes;
use LaravelNecromancer\Diff\ManifestDiff;

test('isFlagged is true for a high or critical risk route', function () {
    expect(FlaggedRoutes::isFlagged(['route_metadata' => ['necromancer' => ['risk' => 'high']]]))->toBeTrue()
        ->and(FlaggedRoutes::isFlagged(['route_metadata' => ['necromancer' => ['risk' => 'critical']]]))->toBeTrue()
        ->and(FlaggedRoutes::isFlagged(['route_metadata' => ['necromancer' => ['risk' => 'low']]]))->toBeFalse();
});

test('isFlagged is true for a route declaring external services', function () {
    expect(FlaggedRoutes::isFlagged(['route_metadata' => ['necromancer' => ['external_services' => ['stripe']]]]))->toBeTrue();
});

test('isFlagged is false for a route with no route metadata', function () {
    expect(FlaggedRoutes::isFlagged(['method' => 'GET', 'uri' => '/orders']))->toBeFalse();
});

test('reason lists domain flow capability risk and external services in order', function () {
    $reason = FlaggedRoutes::reason([
        'route_metadata' => ['necromancer' => [
            'domain' => 'billing',
            'flow' => 'subscription-cancellation',
            'capability' => 'subscription.cancel',
            'risk' => 'high',
            'external_services' => ['stripe'],
        ]],
    ]);

    expect($reason)->toBe('domain: billing · flow: subscription-cancellation · capability: subscription.cancel · risk: high · external services: stripe');
});

test('reason omits fields that are not declared', function () {
    $reason = FlaggedRoutes::reason(['route_metadata' => ['necromancer' => ['risk' => 'high']]]);

    expect($reason)->toBe('risk: high');
});

test('fromDiff returns flagged routes from added and changed, ignores everything else', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [
            ['method' => 'POST', 'uri' => '/billing/cancel', 'route_metadata' => ['necromancer' => ['risk' => 'high']]],
            ['method' => 'GET', 'uri' => '/orders'],
        ]],
        removed: ['routes' => [
            ['method' => 'GET', 'uri' => '/legacy', 'route_metadata' => ['necromancer' => ['risk' => 'high']]],
        ]],
        changed: ['routes' => [
            ['from' => ['method' => 'GET', 'uri' => '/stripe/webhook'], 'to' => ['method' => 'GET', 'uri' => '/stripe/webhook', 'route_metadata' => ['necromancer' => ['external_services' => ['stripe']]]]],
        ]],
    );

    $flagged = FlaggedRoutes::fromDiff($diff);

    expect($flagged)->toHaveCount(2)
        ->and($flagged[0]['uri'])->toBe('/billing/cancel')
        ->and($flagged[1]['uri'])->toBe('/stripe/webhook');
});
