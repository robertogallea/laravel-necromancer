<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\SafeInventoryCollector;
use LaravelNecromancer\Manifest\SourceLocation;
use LaravelNecromancer\Manifest\StructuralArtifact;

test('default vendor and debug route patterns are excluded from the inventory', function (string $routeName) {
    $inventory = (new SafeInventoryCollector)->collect(
        artifacts: [
            StructuralArtifact::route(
                name: $routeName,
                method: 'GET',
                uri: '/'.$routeName,
                middleware: ['web'],
            ),
            StructuralArtifact::route(
                name: 'orders.index',
                method: 'GET',
                uri: '/orders',
                middleware: ['auth'],
            ),
        ],
    );

    expect($inventory->toArray()['artifacts']['routes'])
        ->toHaveCount(1)
        ->toMatchArray([
            [
                'name' => 'orders.index',
                'method' => 'GET',
                'uri' => '/orders',
                'middleware' => ['auth'],
                'controller' => null,
                'action' => null,
            ],
        ]);
})->with([
    'horizon' => 'horizon.dashboard',
    'telescope' => 'telescope.requests',
    'debugbar' => 'debugbar.assets.css',
]);

test('serialized inventory does not include environment secret values', function () {
    $secret = 'necromancer-env-secret-value';

    putenv('NECROMANCER_BASELINE_SECRET='.$secret);
    $_ENV['NECROMANCER_BASELINE_SECRET'] = $secret;
    $_SERVER['NECROMANCER_BASELINE_SECRET'] = $secret;

    try {
        $inventory = (new SafeInventoryCollector)->collect(
            artifacts: [
                StructuralArtifact::route(
                    name: 'orders.index',
                    method: 'GET',
                    uri: '/orders',
                    middleware: ['auth'],
                ),
            ],
        );

        expect($inventory->toJson())->not->toContain($secret);
    } finally {
        putenv('NECROMANCER_BASELINE_SECRET');
        unset($_ENV['NECROMANCER_BASELINE_SECRET'], $_SERVER['NECROMANCER_BASELINE_SECRET']);
    }
});

test('serialized inventory includes configuration keys but not configuration values', function () {
    $secret = 'necromancer-config-secret-value';
    $domain = 'api.example.test';

    $inventory = (new SafeInventoryCollector)->collect(configuration: [
        'app' => [
            'name' => 'Demo Application',
            'key' => $secret,
        ],
        'services' => [
            'example' => [
                'domain' => $domain,
                'secret' => $secret,
            ],
        ],
    ]);

    $serialized = $inventory->toJson();

    expect($inventory->toArray()['configuration']['keys'])
        ->toContain('app')
        ->toContain('app.key')
        ->toContain('app.name')
        ->toContain('services.example.domain')
        ->toContain('services.example.secret');

    expect($serialized)
        ->not->toContain($secret)
        ->not->toContain($domain)
        ->not->toContain('Demo Application');
});

test('serialized inventory preserves allowed structural metadata', function () {
    $inventory = (new SafeInventoryCollector)->collect(
        artifacts: [
            StructuralArtifact::route(
                name: 'orders.index',
                method: 'get',
                uri: '/orders',
                middleware: ['auth', 'verified'],
                source: new SourceLocation('routes/web.php', 12),
            ),
        ],
    );

    $serialized = $inventory->toJson();
    $route = $inventory->toArray()['artifacts']['routes'][0];

    expect($route)
        ->toMatchArray([
            'name' => 'orders.index',
            'method' => 'GET',
            'uri' => '/orders',
            'middleware' => ['auth', 'verified'],
            'source' => [
                'file' => 'routes/web.php',
                'line' => 12,
                'line_end' => null,
                'hash' => null,
            ],
        ]);

    expect($serialized)
        ->toContain('orders.index')
        ->toContain('GET')
        ->toContain('/orders')
        ->toContain('auth');
});

test('unnamed routes remain in the inventory', function () {
    $inventory = (new SafeInventoryCollector)->collect(
        artifacts: [
            StructuralArtifact::route(
                name: null,
                method: 'GET',
                uri: '/health',
                middleware: ['web'],
            ),
        ],
    );

    expect($inventory->toArray()['artifacts']['routes'])
        ->toHaveCount(1)
        ->toMatchArray([
            [
                'name' => null,
                'method' => 'GET',
                'uri' => '/health',
                'middleware' => ['web'],
                'controller' => null,
                'action' => null,
            ],
        ]);
});
