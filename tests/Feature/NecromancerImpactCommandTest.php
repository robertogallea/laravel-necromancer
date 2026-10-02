<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 */
function writeImpactManifest(array $artifacts, bool $complete = true): void
{
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1,
            'generated_at' => now()->addMinute()->toIso8601String(),
            'scope' => ['complete' => $complete, 'artifact_types' => array_keys($artifacts)],
        ],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
}

/**
 * A route authorized against Order, Order's policy, and a test covering it.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function impactCommandArtifacts(): array
{
    return [
        'routes' => [[
            'id' => 'routes:GET:orders',
            'method' => 'GET',
            'uri' => 'orders',
            'authorization' => [['ability' => 'viewAny', 'models' => ['App\\Models\\Order']]],
        ]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy']],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order']],
        'tests' => [['id' => 'tests:tests/Feature/OrderRouteTest.php', 'file' => 'tests/Feature/OrderRouteTest.php', 'subject' => 'routes:GET:orders']],
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 */
function impactOutput(array $parameters): string
{
    Artisan::call('necromancer:impact', $parameters);

    return Artisan::output();
}

beforeEach(fn () => File::delete(base_path('necromancer.json')));
afterEach(fn () => File::delete(base_path('necromancer.json')));

test('the impact command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:impact', ['artifact' => 'models:App\\Models\\Order'])
        ->expectsOutputToContain('Necromancer manifest not found. Run necromancer:scan first.')
        ->assertFailed();
});

test('the impact command groups reached nodes by distance and type, with direction and via', function () {
    writeImpactManifest(impactCommandArtifacts());

    expect(impactOutput(['artifact' => 'models:App\\Models\\Order', '--depth' => 2]))->toBe(<<<'TEXT'
        Impact of App\Models\Order (models:App\Models\Order), depth 2

        Depth 1
          policies
            App\Policies\OrderPolicy  authorized_by →

        Depth 2
          routes
            GET orders  ← authorized_by  via App\Policies\OrderPolicy

        TEXT);
});

test('the impact command defaults to depth 1', function () {
    writeImpactManifest(impactCommandArtifacts());

    expect(impactOutput(['artifact' => 'models:App\\Models\\Order']))
        ->toContain('App\\Policies\\OrderPolicy')
        ->not->toContain('GET orders');
});

test('a fully-qualified class name resolves to its artifact', function () {
    writeImpactManifest(impactCommandArtifacts());

    expect(impactOutput(['artifact' => 'App\\Models\\Order']))
        ->toContain('Impact of App\\Models\\Order (models:App\\Models\\Order), depth 1');
});

test('a class matching several artifacts fails and lists the candidate IDs', function () {
    writeImpactManifest(['middleware' => [
        ['id' => 'middleware:alias:auth', 'alias' => 'auth', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'alias', 'group' => null],
        ['id' => 'middleware:group:web:App\\Http\\Middleware\\Authenticate', 'alias' => null, 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'group', 'group' => 'web'],
    ]]);

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Http\\Middleware\\Authenticate'])
        ->expectsOutputToContain('matches several artifacts')
        ->expectsOutputToContain('middleware:alias:auth')
        ->expectsOutputToContain('middleware:group:web:App\\Http\\Middleware\\Authenticate')
        ->assertFailed();
});

test('an unknown start fails', function () {
    writeImpactManifest(impactCommandArtifacts());

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Ghost'])
        ->expectsOutputToContain("No artifact matches 'App\\Models\\Ghost'")
        ->assertFailed();
});

test('--depth=0 is rejected', function () {
    writeImpactManifest(impactCommandArtifacts());

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Order', '--depth' => '0'])
        ->expectsOutputToContain('--depth option must be an integer of at least 1')
        ->assertFailed();
});

test('an unknown --type value is rejected', function () {
    writeImpactManifest(impactCommandArtifacts());

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Order', '--type' => 'routes,widgets'])
        ->expectsOutputToContain('Unknown --type value(s): widgets')
        ->assertFailed();
});

test('--type filters the display only, so tests reached through other nodes still show', function () {
    writeImpactManifest(impactCommandArtifacts());

    expect(impactOutput(['artifact' => 'App\\Policies\\OrderPolicy', '--depth' => 2, '--type' => 'tests']))->toBe(<<<'TEXT'
        Impact of App\Policies\OrderPolicy (policies:App\Policies\OrderPolicy), depth 2

        Depth 2
          tests
            tests/Feature/OrderRouteTest.php  tested_by →  via GET orders

        TEXT);
});

test('unresolved nodes are marked, and an isolated artifact reports no relationships', function () {
    writeImpactManifest([
        'routes' => [['id' => 'routes:GET:a', 'method' => 'GET', 'uri' => 'a', 'controller' => 'Illuminate\\Routing\\RedirectController', 'action' => '__invoke']],
        'jobs' => [['id' => 'jobs:App\\Jobs\\Lonely', 'class' => 'App\\Jobs\\Lonely']],
    ]);

    expect(impactOutput(['artifact' => 'routes:GET:a']))->toContain('    Illuminate\\Routing\\RedirectController (unresolved)  handled_by →')
        ->and(impactOutput(['artifact' => 'App\\Jobs\\Lonely']))->toContain('No relationships found.');

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Jobs\\Lonely'])->assertSuccessful();
});

test('--json outputs the start, depth, and filtered nodes with their reaching Relationship', function () {
    writeImpactManifest(impactCommandArtifacts());

    $decoded = json_decode(impactOutput(['artifact' => 'App\\Models\\Order', '--depth' => 2, '--type' => 'routes', '--json' => true]), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        'start' => 'models:App\\Models\\Order',
        'depth' => 2,
        'nodes' => [[
            'id' => 'routes:GET:orders',
            'type' => 'routes',
            'distance' => 2,
            'resolved' => true,
            'via' => [
                'from' => 'policies:App\\Policies\\OrderPolicy',
                'relationship' => [
                    'from' => 'routes:GET:orders',
                    'type' => 'authorized_by',
                    'to' => 'policies:App\\Policies\\OrderPolicy',
                    'provenance' => ['reflection'],
                    'resolved' => true,
                    'metadata' => ['ability' => 'viewAny', 'models' => ['App\\Models\\Order']],
                ],
                'direction' => 'in',
            ],
        ]],
    ]);
});

test('an unchanged manifest yields byte-identical output', function () {
    writeImpactManifest(impactCommandArtifacts());
    $parameters = ['artifact' => 'App\\Models\\Order', '--depth' => 3];

    expect(impactOutput($parameters))->toBe(impactOutput($parameters))
        ->and(impactOutput([...$parameters, '--json' => true]))->toBe(impactOutput([...$parameters, '--json' => true]));
});

test('the impact command refuses a stale manifest unless --allow-stale is passed', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => impactCommandArtifacts(),
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Order'])
        ->expectsOutputToContain('may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to analyze anyway.')
        ->assertFailed();

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Order', '--allow-stale' => true])
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('the impact command refuses a partial-scope manifest unless --allow-partial is passed', function () {
    writeImpactManifest(impactCommandArtifacts(), complete: false);

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Order'])
        ->expectsOutputToContain('scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to analyze anyway.')
        ->assertFailed();

    $this->artisan('necromancer:impact', ['artifact' => 'App\\Models\\Order', '--allow-partial' => true])
        ->assertSuccessful();
});
