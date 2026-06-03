<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the audit command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the audit command succeeds when a valid manifest exists at the configured path', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->assertSuccessful();
});

test('the audit command shows score 100 and all checks passed when manifest has no violations', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Score: 100/100')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();
});

test('the audit command shows zero counts in the counts line when manifest has no violations', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('0 errors · 0 warnings · 0 suggestions')
        ->assertSuccessful();
});

test('a manifest with an unnamed route produces an error finding in the output', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
            'models' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Unnamed route: GET /orders')
        ->assertSuccessful();
});

test('a manifest with a closure route produces a suggestion finding in the output', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'POST', 'uri' => '/checkout', 'name' => 'checkout', 'controller' => null, 'action' => null, 'middleware' => [], 'source' => null],
            ],
            'models' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Closure route: POST /checkout')
        ->assertSuccessful();
});

test('a manifest with a model missing fillable produces a warning finding in the output', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => [], 'casts' => ['id' => 'int'], 'relationships' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('No fillable defined: Order')
        ->assertSuccessful();
});

test('a manifest with a model missing casts produces a suggestion finding in the output', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => [], 'relationships' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('No casts defined: Order')
        ->assertSuccessful();
});

test('one unnamed route out of one total route shows a proportional pass-rate score', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
            'models' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    // error weight=3, suggestion weight=1; 0/1 pass error check, 1/1 pass closure check
    // totalWeight=4, passedWeight=1 → round(1/4*100)=25
    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Score: 25/100')
        ->assertSuccessful();
});

test('all named routes and models with fillable and casts show all checks passed and score 100', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => ['id' => 'int'], 'relationships' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Score: 100/100')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();
});

test('a manifest with a command that has an empty description produces a warning finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [],
            'models' => [],
            'commands' => [
                ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => '', 'source' => null],
            ],
            'events' => [],
            'jobs' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Empty description: orders:prune')
        ->assertSuccessful();
});

test('a manifest with an event that has no listeners produces a warning finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [],
            'models' => [],
            'commands' => [],
            'events' => [
                ['class' => 'App\\Events\\OrderPlaced', 'listeners' => [], 'source' => null],
            ],
            'jobs' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('No listeners: OrderPlaced')
        ->assertSuccessful();
});

test('a manifest with a job that has no queue name produces a suggestion finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [],
            'models' => [],
            'commands' => [],
            'events' => [],
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => null, 'connection' => 'redis', 'tries' => 3, 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('No queue name: SendInvoiceEmail')
        ->assertSuccessful();
});

test('one command with an empty description out of one total command shows score 0 out of 100', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [],
            'models' => [],
            'commands' => [
                ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => '', 'source' => null],
            ],
            'events' => [],
            'jobs' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    // warning weight=2; 0/1 pass description check → round(0/2*100)=0
    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Score: 0/100')
        ->assertSuccessful();
});

test('two routes with one unnamed shows proportional score that does not bottom out', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
                ['method' => 'GET', 'uri' => '/products', 'name' => 'products.index', 'controller' => 'ProductController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
            'models' => [],
        ],
    ], JSON_THROW_ON_ERROR));

    // error weight=3, suggestion weight=1; 1/2 pass error check, 2/2 pass closure check
    // totalWeight=8, passedWeight=5 → round(5/8*100)=63
    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Score: 63/100')
        ->assertSuccessful();
});

test('commands with descriptions events with listeners and jobs with queue names show all checks passed', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index', 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => ['name'], 'casts' => ['id' => 'int'], 'relationships' => [], 'source' => null],
            ],
            'commands' => [
                ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune', 'description' => 'Remove old orders', 'source' => null],
            ],
            'events' => [
                ['class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendOrderConfirmation'], 'source' => null],
            ],
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Score: 100/100')
        ->expectsOutputToContain('All checks passed.')
        ->assertSuccessful();
});

test('--format=json outputs valid JSON with score counts and findings keys', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:audit', ['--format' => 'json']);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKeys(['score', 'counts', 'findings']);
});

test('--format=json with violations includes the correct finding in the findings array', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:audit', ['--format' => 'json']);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['findings'])->toHaveCount(1)
        ->and($decoded['findings'][0]['message'])->toBe('Unnamed route: GET /orders')
        ->and($decoded['findings'][0]['severity'])->toBe('error');
});

test('--format=json with no violations outputs score 100 and empty findings array', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    Artisan::call('necromancer:audit', ['--format' => 'json']);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['score'])->toBe(100)
        ->and($decoded['findings'])->toBeEmpty()
        ->and($decoded['counts']['errors'])->toBe(0);
});

test('--output= writes the text report to a file and produces no terminal output', function () {
    $reportPath = storage_path('framework/testing/audit-report.txt');
    File::delete($reportPath);

    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit', ['--output' => $reportPath])
        ->assertSuccessful();

    expect(File::exists($reportPath))->toBeTrue()
        ->and(File::get($reportPath))->toContain('Score: 100/100');

    File::delete($reportPath);
});

test('--format=json --output= writes the JSON report to a file', function () {
    $reportPath = storage_path('framework/testing/audit-report.json');
    File::delete($reportPath);

    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit', ['--format' => 'json', '--output' => $reportPath])
        ->assertSuccessful();

    expect(File::exists($reportPath))->toBeTrue();
    $decoded = json_decode(File::get($reportPath), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toHaveKeys(['score', 'counts', 'findings']);

    File::delete($reportPath);
});

test('--format=markdown outputs a markdown heading and bullet findings', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders', 'name' => null, 'controller' => 'OrderController', 'action' => 'index', 'middleware' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit', ['--format' => 'markdown'])
        ->expectsOutputToContain('## Laravel Necromancer')
        ->expectsOutputToContain('### Errors')
        ->expectsOutputToContain('- Unnamed route: GET /orders')
        ->assertSuccessful();
});

test('--format=markdown with no violations outputs the all-checks-passed message', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit', ['--format' => 'markdown'])
        ->expectsOutputToContain('_All checks passed._')
        ->assertSuccessful();
});

test('--output= with a non-writable path fails with a clear error and exits non-zero', function () {
    File::put(base_path('necromancer.json'), json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit', ['--output' => '/nonexistent-directory/report.txt'])
        ->expectsOutputToContain('Unable to write audit report')
        ->assertFailed();
});

test('the audit command warns when app files are newer than the manifest generated_at', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['generated_at' => '1970-01-01T00:00:00+00:00'],
        'artifacts' => (object) [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('stale')
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('the audit command does not warn when the manifest generated_at is in the future', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['generated_at' => '2099-01-01T00:00:00+00:00'],
        'artifacts' => (object) [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->doesntExpectOutputToContain('stale')
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('a manifest with a model with guarded=[] produces a warning finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'models' => [
                ['class' => 'App\\Models\\Order', 'table' => 'orders', 'fillable' => [], 'casts' => [], 'relationships' => [], 'guarded' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Open mass-assignment guard')
        ->assertSuccessful();
});

test('a manifest with a non-GET route without auth middleware produces a suggestion finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'POST', 'uri' => '/orders', 'name' => 'orders.store', 'controller' => 'OrderController', 'action' => 'store', 'middleware' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Non-GET route without auth middleware: POST /orders')
        ->assertSuccessful();
});

test('a non-GET route with auth middleware does not produce a suggestion finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [
                ['method' => 'POST', 'uri' => '/orders', 'name' => 'orders.store', 'controller' => 'OrderController', 'action' => 'store', 'middleware' => ['auth'], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->doesntExpectOutputToContain('Non-GET route without auth middleware')
        ->assertSuccessful();
});

test('a manifest with a job that has no timeout produces a warning finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => 3, 'timeout' => null, 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('No timeout defined: SendInvoiceEmail')
        ->assertSuccessful();
});

test('a manifest with a job that has no tries produces a suggestion finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'connection' => 'redis', 'tries' => null, 'timeout' => 60, 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('No retry count defined: SendInvoiceEmail')
        ->assertSuccessful();
});

test('a manifest with a broadcastable event with no channel produces a warning finding', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => [],
        'artifacts' => [
            'events' => [
                ['class' => 'App\\Events\\OrderShipped', 'listeners' => [], 'broadcastable' => true, 'channels' => [], 'source' => null],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('Broadcastable event with no channel defined: OrderShipped')
        ->assertSuccessful();
});

test('the audit command resolves the manifest path from config', function () {
    $path = storage_path('framework/testing/necromancer-audit-config.json');
    config(['necromancer.output.manifest' => $path]);

    $this->artisan('necromancer:audit')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();

    File::put($path, json_encode(['meta' => [], 'artifacts' => (object) []], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:audit')
        ->assertSuccessful();

    File::delete($path);
});
