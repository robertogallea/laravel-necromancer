<?php

use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\PendingSingletonResourceRegistration;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route;
use LaravelNecromancer\Tests\Fixtures\NecromancerFakeMetadataRoute;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('route-metadata');

/**
 * Native route metadata landed in Laravel 13.17. The package supports ^13.0, and the
 * framework version installed when the suite runs varies (the package's own vendor
 * directory may predate 13.17 while CI floats to the latest 13.*), so the group and
 * resource surfaces — which have no test double — are asserted conditionally.
 */
$metadataSupported = method_exists(RoutingRoute::class, 'metadata');

test('the withNecromancer macro is registered on every routing surface', function () {
    expect(Router::hasMacro('withNecromancer'))->toBeTrue()
        ->and(RouteRegistrar::hasMacro('withNecromancer'))->toBeTrue()
        ->and(RoutingRoute::hasMacro('withNecromancer'))->toBeTrue()
        ->and(PendingResourceRegistration::hasMacro('withNecromancer'))->toBeTrue()
        ->and(PendingSingletonResourceRegistration::hasMacro('withNecromancer'))->toBeTrue();
});

test('withNecromancer writes every supplied field under the necromancer namespace', function () {
    $route = new NecromancerFakeMetadataRoute(['POST'], '/billing/cancel', ['uses' => fn () => 'ok']);

    $returned = $route->withNecromancer(
        domain: 'billing',
        flow: 'subscription-cancellation',
        capability: 'subscription.cancel',
        summary: 'Cancels an active subscription.',
        risk: 'high',
        externalServices: ['stripe'],
        adr: 'docs/adr/004-subscription-cancellation.md',
    );

    expect($returned)->toBe($route)
        ->and($route->getMetadata())->toBe([
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

test('withNecromancer wraps a single external service string into a list', function () {
    $route = new NecromancerFakeMetadataRoute(['GET'], '/webhooks/stripe', ['uses' => fn () => 'ok']);

    $route->withNecromancer(externalServices: 'stripe');

    expect($route->getMetadata('necromancer.external_services'))->toBe(['stripe']);
});

test('withNecromancer called with no arguments writes nothing', function () {
    $route = new NecromancerFakeMetadataRoute(['GET'], '/plain', ['uses' => fn () => 'ok']);

    $route->withNecromancer();

    expect($route->getMetadata())->toBe([]);
});

test('withNecromancer respects a custom route_metadata namespace', function () {
    config(['necromancer.route_metadata.namespace' => 'acme']);

    $route = new NecromancerFakeMetadataRoute(['GET'], '/custom-namespace', ['uses' => fn () => 'ok']);

    $route->withNecromancer(domain: 'billing');

    expect($route->getMetadata())->toBe(['acme' => ['domain' => 'billing']]);
});

test('withNecromancer merges with metadata declared by other namespaces', function () {
    $route = new NecromancerFakeMetadataRoute(['GET'], '/mixed', ['uses' => fn () => 'ok']);

    $route->metadata(['head' => ['title' => 'Billing']]);
    $route->withNecromancer(domain: 'billing');

    expect($route->getMetadata())->toBe([
        'head' => ['title' => 'Billing'],
        'necromancer' => ['domain' => 'billing'],
    ]);
});

test('withNecromancer fails loudly when the framework has no native route metadata', function () {
    $route = new RoutingRoute(['GET'], '/unsupported', ['uses' => fn () => 'ok']);

    expect(fn () => $route->withNecromancer(domain: 'billing'))
        ->toThrow(RuntimeException::class, '13.17');
})->skip($metadataSupported, 'The installed Laravel supports native route metadata.');

/**
 * Resolve a registered route by name.
 *
 * ->name() is called after the route has been added to the collection, so the
 * name lookup table has to be refreshed before the route can be found by name.
 */
function necromancerRegisteredRoute(string $name): ?RoutingRoute
{
    $routes = app(Router::class)->getRoutes();
    $routes->refreshNameLookups();

    return $routes->getByName($name);
}

test('withNecromancer tags every route in a group', function () {
    Route::withNecromancer(domain: 'billing', risk: 'high')
        ->prefix('billing')
        ->group(function () {
            Route::post('/cancel', fn () => 'ok')->name('billing.cancel');
        });

    $route = necromancerRegisteredRoute('billing.cancel');

    expect($route->getMetadata('necromancer.domain'))->toBe('billing')
        ->and($route->getMetadata('necromancer.risk'))->toBe('high');
})->skip(! $metadataSupported, 'Requires Laravel 13.17+ native route metadata.');

test('withNecromancer tags a group it is chained onto', function () {
    Route::prefix('billing')
        ->withNecromancer(domain: 'billing')
        ->group(function () {
            Route::get('/invoices', fn () => 'ok')->name('billing.invoices');
        });

    $route = necromancerRegisteredRoute('billing.invoices');

    expect($route->getMetadata('necromancer.domain'))->toBe('billing');
})->skip(! $metadataSupported, 'Requires Laravel 13.17+ native route metadata.');

test('withNecromancer tags a resource registration', function () {
    Route::resource('posts', 'PostController')->withNecromancer(domain: 'blog');

    $route = necromancerRegisteredRoute('posts.index');

    expect($route->getMetadata('necromancer.domain'))->toBe('blog');
})->skip(! $metadataSupported, 'Requires Laravel 13.17+ native route metadata.');

test('withNecromancer tags a singleton resource registration', function () {
    Route::singleton('profile', 'ProfileController')->withNecromancer(domain: 'account');

    $route = necromancerRegisteredRoute('profile.show');

    expect($route->getMetadata('necromancer.domain'))->toBe('account');
})->skip(! $metadataSupported, 'Requires Laravel 13.17+ native route metadata.');
