<?php

use Illuminate\Support\Facades\File;

function okfManifest(array $artifacts = [], bool $complete = true): array
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
    File::deleteDirectory(base_path('okf'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('okf'));
});

test('the okf command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:okf')
        ->assertSuccessful();
});

test('the okf command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the okf command exports a bundle to the default output directory', function () {
    File::put(base_path('necromancer.json'), json_encode(okfManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('1 artifact concept')
        ->assertSuccessful();

    expect(File::isDirectory(base_path('okf/artifacts')))->toBeTrue()
        ->and(File::isFile(base_path('okf/bundle.json')))->toBeTrue()
        ->and(count(File::glob(base_path('okf/artifacts/*.md'))))->toBe(1);
});

test('the okf command refuses a stale manifest by default', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale')
        ->assertFailed();

    expect(File::isDirectory(base_path('okf')))->toBeFalse();

    File::deleteDirectory(base_path('app'));
});

test('the okf command exports a stale manifest with --allow-stale', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf', ['--allow-stale' => true])
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('the okf command refuses a partial-scope manifest by default', function () {
    File::put(base_path('necromancer.json'), json_encode(okfManifest([], complete: false), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial')
        ->assertFailed();

    expect(File::isDirectory(base_path('okf')))->toBeFalse();
});

test('the okf command exports a partial-scope manifest with --allow-partial', function () {
    File::put(base_path('necromancer.json'), json_encode(okfManifest([], complete: false), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf', ['--allow-partial' => true])
        ->assertSuccessful();
});

test('--output overrides the default bundle directory', function () {
    $customPath = storage_path('framework/testing/okf-custom-bundle');
    File::deleteDirectory($customPath);

    File::put(base_path('necromancer.json'), json_encode(okfManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf', ['--output' => $customPath])
        ->assertSuccessful();

    expect(File::isDirectory($customPath.'/artifacts'))->toBeTrue()
        ->and(File::isDirectory(base_path('okf')))->toBeFalse();

    File::deleteDirectory($customPath);
});

test('the okf command fails when a declared local ADR file is missing', function () {
    File::put(base_path('necromancer.json'), json_encode(okfManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['adrs' => ['docs/adr/missing.md']], 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('docs/adr/missing.md')
        ->assertFailed();

    expect(File::isDirectory(base_path('okf')))->toBeFalse();
});

test('the okf command copies a declared local ADR and synthesizes domain/flow concepts', function () {
    File::ensureDirectoryExists(base_path('docs/adr'));
    File::put(base_path('docs/adr/0004-x.md'), "# ADR 0004\n\nDecision text.");

    File::put(base_path('necromancer.json'), json_encode(okfManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing', 'flow' => 'invoicing', 'adrs' => ['docs/adr/0004-x.md']], 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('1 artifact concept')
        ->assertSuccessful();

    expect(count(File::glob(base_path('okf/artifacts/*.md'))))->toBe(4);

    File::deleteDirectory(base_path('docs/adr'));
});

test('the okf command resolves the manifest path from config', function () {
    $path = storage_path('framework/testing/necromancer-okf-config.json');
    config(['necromancer.output.manifest' => $path]);

    $this->artisan('necromancer:okf')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();

    File::put($path, json_encode(okfManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf')
        ->assertSuccessful();

    File::delete($path);
});
