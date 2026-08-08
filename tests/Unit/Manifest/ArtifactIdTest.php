<?php

declare(strict_types=1);

use LaravelNecromancer\Manifest\ArtifactId;

test('derives canonical IDs for every artifact family', function () {
    $ids = (new ArtifactId)->assign([
        'routes' => [[
            'method' => 'head|get|POST|get',
            'uri' => 'orders/{order}',
        ]],
        'models' => [['class' => '\\App\\Models\\Order']],
        'form_requests' => [['class' => 'App\\Http\\Requests\\StoreOrderRequest']],
        'jobs' => [['class' => 'App\\Jobs\\SyncOrder']],
        'events' => [['class' => 'App\\Events\\OrderPlaced']],
        'listeners' => [['class' => 'App\\Listeners\\NotifyCustomer']],
        'commands' => [['class' => 'App\\Console\\Commands\\SyncOrders']],
        'policies' => [['class' => 'App\\Policies\\OrderPolicy']],
        'enums' => [['class' => 'App\\Enums\\OrderStatus']],
        'observers' => [['class' => 'App\\Observers\\OrderObserver']],
        'livewire_components' => [['class' => 'App\\Livewire\\OrdersTable']],
        'mailables' => [['class' => 'App\\Mail\\OrderReceipt']],
        'validation_rules' => [['class' => 'App\\Rules\\ValidOrderNumber']],
        'service_providers' => [['class' => 'App\\Providers\\OrderServiceProvider']],
        'tests' => [['file' => 'tests\\Feature\\OrdersTest.php']],
        'gates' => [
            ['ability' => 'view-order', 'kind' => 'closure'],
            ['ability' => '__before__', 'kind' => 'before_hook'],
            ['ability' => '__before__', 'kind' => 'before_hook'],
            ['ability' => '__after__', 'kind' => 'after_hook'],
        ],
        'middleware' => [
            ['scope' => 'global', 'class' => 'App\\Http\\Middleware\\Authenticate', 'alias' => 'auth', 'group' => null],
            ['scope' => 'group', 'class' => 'App\\Http\\Middleware\\Authenticate', 'alias' => 'auth', 'group' => 'web'],
            ['scope' => 'alias', 'class' => 'App\\Http\\Middleware\\Authenticate', 'alias' => 'auth', 'group' => null],
        ],
        'scheduled_tasks' => [
            ['command' => 'inspire', 'expression' => '0 0 * * *', 'without_overlapping' => false, 'run_in_background' => false, 'even_in_maintenance' => false, 'timezone' => null, 'description' => null],
            ['command' => 'inspire', 'expression' => '0 0 * * *', 'without_overlapping' => false, 'run_in_background' => false, 'even_in_maintenance' => false, 'timezone' => null, 'description' => null],
        ],
    ]);

    expect($ids['routes'][0]['id'])->toBe('routes:GET|POST:orders/{order}')
        ->and($ids['models'][0]['id'])->toBe('models:App\\Models\\Order')
        ->and($ids['tests'][0]['id'])->toBe('tests:tests/Feature/OrdersTest.php')
        ->and($ids['gates'][0]['id'])->toBe('gates:ability:view-order')
        ->and($ids['gates'][1]['id'])->toBe('gates:before_hook:0')
        ->and($ids['gates'][2]['id'])->toBe('gates:before_hook:1')
        ->and($ids['gates'][3]['id'])->toBe('gates:after_hook:0')
        ->and($ids['middleware'][0]['id'])->toBe('middleware:global:App\\Http\\Middleware\\Authenticate')
        ->and($ids['middleware'][1]['id'])->toBe('middleware:group:web:App\\Http\\Middleware\\Authenticate')
        ->and($ids['middleware'][2]['id'])->toBe('middleware:alias:auth')
        ->and($ids['scheduled_tasks'][0]['id'])->toMatch('/^scheduled_tasks:[a-f0-9]{64}:1$/')
        ->and($ids['scheduled_tasks'][1]['id'])->toMatch('/^scheduled_tasks:[a-f0-9]{64}:2$/');
});

test('rejects Artifact ID collisions', function () {
    expect(fn (): array => (new ArtifactId)->assign([
        'models' => [
            ['class' => 'App\\Models\\Order'],
            ['class' => 'App\\Models\\Order'],
        ],
    ]))->toThrow(LogicException::class, 'Duplicate Artifact ID');
});
