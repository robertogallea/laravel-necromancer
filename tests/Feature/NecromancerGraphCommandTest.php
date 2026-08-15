<?php

use Illuminate\Support\Facades\File;

function graphManifest(array $artifacts = [], bool $complete = true): array
{
    return [
        'meta' => ['manifest_schema_version' => 1,
            'generated_at' => now()->addMinute()->toIso8601String(),
            'scope' => ['complete' => $complete, 'artifact_types' => array_keys($artifacts)],
        ],
        'artifacts' => $artifacts,
    ];
}

beforeEach(function () {
    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('necromancer-graph'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('necromancer-graph'));
});

test('the graph command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:graph')
        ->assertSuccessful();
});

test('the graph command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:graph')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the graph command builds a graph in the default output directory', function () {
    File::put(base_path('necromancer.json'), json_encode(graphManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph')
        ->expectsOutputToContain('1 node(s)')
        ->assertSuccessful();

    expect(File::isFile(base_path('necromancer-graph/graph.json')))->toBeTrue()
        ->and(File::isFile(base_path('necromancer-graph/graph.html')))->toBeTrue();

    $decoded = json_decode(File::get(base_path('necromancer-graph/graph.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['nodes'])->toHaveCount(1)
        ->and($decoded['edges'])->toBe([]);
});

test('the graph command refuses a stale manifest by default', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph')
        ->expectsOutputToContain('may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale')
        ->assertFailed();

    expect(File::isDirectory(base_path('necromancer-graph')))->toBeFalse();

    File::deleteDirectory(base_path('app'));
});

test('the graph command builds a stale manifest with --allow-stale', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph', ['--allow-stale' => true])
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('the graph command refuses a partial-scope manifest by default', function () {
    File::put(base_path('necromancer.json'), json_encode(graphManifest([], complete: false), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph')
        ->expectsOutputToContain('scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial')
        ->assertFailed();

    expect(File::isDirectory(base_path('necromancer-graph')))->toBeFalse();
});

test('the graph command builds a partial-scope manifest with --allow-partial', function () {
    File::put(base_path('necromancer.json'), json_encode(graphManifest([], complete: false), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph', ['--allow-partial' => true])
        ->assertSuccessful();
});

test('--output overrides the default graph directory', function () {
    $customPath = storage_path('framework/testing/graph-custom');
    File::deleteDirectory($customPath);

    File::put(base_path('necromancer.json'), json_encode(graphManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph', ['--output' => $customPath])
        ->assertSuccessful();

    expect(File::isFile($customPath.'/graph.json'))->toBeTrue()
        ->and(File::isFile($customPath.'/graph.html'))->toBeTrue()
        ->and(File::isDirectory(base_path('necromancer-graph')))->toBeFalse();

    File::deleteDirectory($customPath);
});

test('the graph command resolves the output directory from config', function () {
    $customPath = storage_path('framework/testing/graph-configured');
    File::deleteDirectory($customPath);
    config(['necromancer.output.graph' => $customPath]);

    File::put(base_path('necromancer.json'), json_encode(graphManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph')
        ->assertSuccessful();

    expect(File::isFile($customPath.'/graph.json'))->toBeTrue();

    File::deleteDirectory($customPath);
});

test('the graph command resolves the manifest path from config', function () {
    $path = storage_path('framework/testing/necromancer-graph-config.json');
    config(['necromancer.output.manifest' => $path]);

    $this->artisan('necromancer:graph')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();

    File::put($path, json_encode(graphManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:graph')
        ->assertSuccessful();

    File::delete($path);
});
