<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the map command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:map')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the map command succeeds when a valid manifest exists at the configured path', function () {
    $manifest = json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->assertSuccessful();
});

test('the map command resolves the manifest path from config', function () {
    $path = storage_path('framework/testing/necromancer-map-config.json');
    config(['necromancer.output.manifest' => $path]);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();

    File::put($path, json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:map')
        ->assertSuccessful();

    File::delete($path);
});

test('the map command displays route artifacts with method uri name and middleware', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => ['auth', 'verified'], 'controller' => null, 'action' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    // Each expectsOutputToContain must target a distinct doWrite call; the heading and the
    // combined artifact line each produce exactly one doWrite call, so two assertions work.
    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Routes')
        ->expectsOutputToContain('GET  /orders  orders.index  auth,verified')
        ->assertSuccessful();
});

test('the map command displays model artifacts with short class table and relationships', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'models' => [
                [
                    'class' => 'App\\Models\\Order',
                    'table' => 'orders',
                    'fillable' => ['customer_id'],
                    'casts' => [],
                    'relationships' => [
                        ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => 'customer'],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Models')
        ->expectsOutputToContain('Order  orders  belongsTo:Customer')
        ->assertSuccessful();
});

test('the map command displays job artifacts with short class and labeled queue fields', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Jobs')
        ->expectsOutputToContain('SendInvoiceEmail  queue:emails  connection:redis  tries:3')
        ->assertSuccessful();
});

test('the map command displays event artifacts with short class and short listener names', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'events' => [
                ['class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendOrderConfirmation', 'App\\Listeners\\SendAuditLog']],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Events')
        ->expectsOutputToContain('OrderPlaced  SendOrderConfirmation,SendAuditLog')
        ->assertSuccessful();
});

test('the map command displays listener artifacts with short class handles and queued flag', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'listeners' => [
                ['class' => 'App\\Listeners\\SendOrderConfirmation', 'handles' => ['App\\Events\\OrderPlaced'], 'queued' => true],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Listeners')
        ->expectsOutputToContain('SendOrderConfirmation  OrderPlaced  queued')
        ->assertSuccessful();
});

test('the map command displays command artifacts with signature and description', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'commands' => [
                ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune {--days=30}', 'description' => 'Prune old orders'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Commands')
        ->expectsOutputToContain('orders:prune {--days=30}  Prune old orders')
        ->assertSuccessful();
});

test('the map command succeeds and produces no artifact output for an empty manifest', function () {
    $manifest = json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->doesntExpectOutputToContain('Routes')
        ->doesntExpectOutputToContain('Models')
        ->doesntExpectOutputToContain('Jobs')
        ->doesntExpectOutputToContain('Events')
        ->doesntExpectOutputToContain('Listeners')
        ->doesntExpectOutputToContain('Commands')
        ->assertSuccessful();
});

test('the map command with --type=routes shows only the routes section', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => []],
            ],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'relationships' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map', ['--type' => 'routes'])
        ->expectsOutputToContain('Routes')
        ->doesntExpectOutputToContain('Models')
        ->assertSuccessful();
});

test('the map command with --type=models shows only the models section', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => []],
            ],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'relationships' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map', ['--type' => 'models'])
        ->expectsOutputToContain('Models')
        ->doesntExpectOutputToContain('Routes')
        ->assertSuccessful();
});

test('the map command fails with an actionable message when --type names an unknown group', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map', ['--type' => 'unknown'])
        ->expectsOutputToContain('Available types: routes')
        ->assertFailed();
});

test('the map command without --type still shows all artifact groups', function () {
    $manifest = json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => []],
            ],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'relationships' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('Routes')
        ->expectsOutputToContain('Models')
        ->assertSuccessful();
});

// Stale manifest warning

test('the map command warns when app files are newer than the manifest generated_at', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['generated_at' => '1970-01-01T00:00:00+00:00'],
        'artifacts' => (object) [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:map')
        ->expectsOutputToContain('stale')
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('the map command does not warn when the manifest generated_at is in the future', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['generated_at' => '2099-01-01T00:00:00+00:00'],
        'artifacts' => (object) [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:map')
        ->doesntExpectOutputToContain('stale')
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('map shows observers for models that have them', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'models' => [[
                'class' => 'App\\Models\\Order',
                'table' => 'orders',
                'fillable' => [],
                'casts' => [],
                'relationships' => [],
                'soft_deletes' => false,
                'guarded' => ['*'],
                'observers' => ['App\\Observers\\OrderObserver'],
                'global_scopes' => ['App\\Scopes\\TenantScope'],
            ]],
        ],
    ]));

    $this->artisan('necromancer:map', ['--type' => 'models'])
        ->expectsOutputToContain('OrderObserver')
        ->assertSuccessful();
});

test('map shows aliases for commands that have them', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'commands' => [[
                'class' => 'App\\Console\\Commands\\CleanOrders',
                'signature' => 'orders:cleanup',
                'description' => 'Clean orders',
                'aliases' => ['oc', 'orders:clean'],
            ]],
        ],
    ]));

    $this->artisan('necromancer:map', ['--type' => 'commands'])
        ->expectsOutputToContain('oc')
        ->assertSuccessful();
});
