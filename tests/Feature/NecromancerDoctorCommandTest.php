<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the doctor command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:doctor')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the doctor command succeeds when a valid manifest exists', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor')
        ->assertSuccessful();
});

test('the doctor command shows 100% score for an empty manifest', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor')
        ->expectsOutputToContain('Score: 100%')
        ->assertSuccessful();
});

test('all named controller-backed routes give route clarity 100 percent', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'controller' => 'OrderController', 'middleware' => []],
                ['method' => 'POST', 'uri' => '/orders', 'name' => 'orders.store', 'controller' => 'OrderController', 'middleware' => ['auth']],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor')
        ->expectsOutputToContain('Route Clarity')
        ->expectsOutputToContain('100%')
        ->assertSuccessful();
});

test('an unnamed route reduces the route clarity score below 100 percent', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'middleware' => []],
                ['method' => 'GET', 'uri' => '/users', 'name' => 'users.index', 'controller' => 'UserController', 'middleware' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Route Clarity');
    preg_match('/Route Clarity\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 100))->toBeLessThan(100);
});

test('models with casts and fillable and relationships give 100 percent model expressiveness', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'models' => [
                [
                    'class' => 'App\\Models\\Order',
                    'casts' => ['id' => 'int', 'created_at' => 'datetime'],
                    'fillable' => ['name', 'status'],
                    'relationships' => [['type' => 'hasMany', 'related' => 'App\\Models\\Item', 'method' => 'items']],
                    'guarded' => ['*'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    expect($output)->toContain('Model Expressiveness');
    preg_match('/Model Expressiveness\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 0))->toBe(100);
});

test('models without casts fillable or relationships give 0 percent model expressiveness', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'models' => [
                ['class' => 'App\\Models\\Order', 'casts' => [], 'fillable' => [], 'relationships' => [], 'guarded' => ['*']],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Model Expressiveness\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 100))->toBe(0);
});

test('jobs with queue tries timeout and backoff give 100 percent async clarity', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoice', 'queue' => 'emails', 'tries' => 3, 'timeout' => 60, 'backoff' => 5],
            ],
            'events' => [
                ['class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendReceipt']],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Async Clarity\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 0))->toBe(100);
});

test('a job without a queue name reduces async clarity score', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoice', 'queue' => null, 'tries' => 3, 'timeout' => 60],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Async Clarity\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 100))->toBeLessThan(100);
});

test('commands with descriptions and backed enums give 100 percent codebase vocabulary', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'commands' => [
                ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => 'Remove old orders'],
            ],
            'enums' => [
                ['class' => 'App\\Enums\\Status', 'backing_type' => 'string', 'cases' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Codebase Vocabulary\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 0))->toBe(100);
});

test('commands without descriptions reduce codebase vocabulary score', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'commands' => [
                ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => ''],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Codebase Vocabulary\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 100))->toBeLessThan(100);
});

test('write routes covered by form requests give 100 percent validation coverage', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'POST', 'uri' => '/orders', 'name' => 'orders.store', 'controller' => 'OrderController', 'middleware' => []],
            ],
            'requests' => [
                ['class' => 'App\\Http\\Requests\\StoreOrderRequest', 'rules' => ['name' => 'required']],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Validation Coverage\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 0))->toBe(100);
});

test('models with corresponding policies give full authorization coverage', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'models' => [
                ['class' => 'App\\Models\\Order', 'casts' => [], 'fillable' => [], 'relationships' => []],
            ],
            'policies' => [
                ['class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order', 'methods' => []],
            ],
            'routes' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor');
    $output = Artisan::output();

    preg_match('/Authorization Coverage\s+\S+\s+(\d+)%/', $output, $matches);
    expect((int) ($matches[1] ?? 0))->toBe(100);
});

test('--json outputs valid JSON with score and dimensions keys', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKeys(['score', 'dimensions'])
        ->and($decoded['score'])->toBe(100)
        ->and($decoded['dimensions'])->toBeArray();
});

test('--json dimension entries have key label score detail and weight', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['dimensions'])->not->toBeEmpty();
    expect($decoded['dimensions'][0])->toHaveKeys(['key', 'label', 'score', 'detail', 'weight']);
});

test('--min-score exits non-zero when overall score is below the threshold', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => null, 'middleware' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor', ['--min-score' => '99'])
        ->assertFailed();
});

test('--min-score exits successfully when overall score meets the threshold', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor', ['--min-score' => '100'])
        ->assertSuccessful();
});

test('--only limits the output to the specified dimensions', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor', ['--only' => 'route-clarity'])
        ->expectsOutputToContain('Route Clarity')
        ->doesntExpectOutputToContain('Model Expressiveness')
        ->assertSuccessful();
});

test('--only with json only includes the specified dimensions in dimensions array', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:doctor', ['--json' => true, '--only' => 'route-clarity,async-clarity']);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['dimensions'])->toHaveCount(2);
    $keys = array_column($decoded['dimensions'], 'key');
    expect($keys)->toContain('route-clarity')->toContain('async-clarity');
});

test('the doctor command warns when manifest is stale', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['generated_at' => '1970-01-01T00:00:00+00:00'],
        'artifacts' => (object) [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor')
        ->expectsOutputToContain('stale')
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('the doctor command resolves the manifest path from config', function () {
    $path = storage_path('framework/testing/necromancer-doctor-config.json');
    config(['necromancer.output.manifest' => $path]);

    $this->artisan('necromancer:doctor')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();

    File::put($path, json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:doctor')
        ->assertSuccessful();

    File::delete($path);
});

test('doctor async clarity scores higher when all jobs have backoff configured', function () {
    // One job with backoff, one without
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\A', 'queue' => 'default', 'connection' => 'redis', 'tries' => 3, 'timeout' => 60, 'backoff' => 30],
                ['class' => 'App\\Jobs\\B', 'queue' => 'default', 'connection' => 'redis', 'tries' => 3, 'timeout' => 60],
            ],
            'events' => [],
        ],
    ]));

    Artisan::call('necromancer:doctor', ['--json' => true, '--only' => 'async-clarity']);
    $partialScore = json_decode(Artisan::output(), true)['dimensions'][0]['score'] ?? 0;

    // Both jobs with backoff
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\A', 'queue' => 'default', 'connection' => 'redis', 'tries' => 3, 'timeout' => 60, 'backoff' => 30],
                ['class' => 'App\\Jobs\\B', 'queue' => 'default', 'connection' => 'redis', 'tries' => 3, 'timeout' => 60, 'backoff' => 60],
            ],
            'events' => [],
        ],
    ]));

    Artisan::call('necromancer:doctor', ['--json' => true, '--only' => 'async-clarity']);
    $fullScore = json_decode(Artisan::output(), true)['dimensions'][0]['score'] ?? 0;

    expect($fullScore)->toBeGreaterThan($partialScore);
});
