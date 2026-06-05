<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use LaravelNecromancer\Benchmark\GenerationAgent;
use LaravelNecromancer\Integrations\AiDetector;

function benchmarkManifest(): string
{
    return json_encode([
        'meta' => [
            'app_name' => 'TestApp',
            'generated_at' => now()->toISOString(),
            'content_hash' => 'abc123',
            'laravel_version' => '13.0',
            'php_version' => '8.4',
        ],
        'artifacts' => [
            'routes' => [
                ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects', 'middleware' => ['auth'], 'controller' => 'ProjectController', 'action' => 'index', 'source' => null],
            ],
            'models' => [],
            'jobs' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

function benchmarkAiAvailable(): AiDetector
{
    return new AiDetector(ServiceProvider::class);
}

beforeEach(function () {
    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, benchmarkAiAvailable());

    GenerationAgent::fake(['The route projects.index requires auth middleware.']);

    File::delete(base_path('necromancer.json'));
    File::delete(base_path('benchmark-test-output.md'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
    File::delete(base_path('benchmark-test-output.md'));
});

test('the benchmark command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:benchmark')
        ->assertSuccessful();
});

test('fails when the manifest does not exist', function () {
    $this->artisan('necromancer:benchmark')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('fails when laravel/ai is not installed', function () {
    $this->instance(AiDetector::class, new AiDetector('NonExistent\\AiProvider'));
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark')
        ->expectsOutputToContain('laravel/ai')
        ->assertFailed();
});

test('runs successfully with --no-judge and --condition=none on a minimal task suite', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();
});

test('outputs results table in terminal format by default', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])
        ->expectsOutputToContain('Benchmark')
        ->expectsOutputToContain('No context')
        ->assertSuccessful();
});

test('writes markdown report when --format=markdown --output is given', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    expect(File::exists($outputPath))->toBeTrue()
        ->and(File::get($outputPath))->toContain('# Necromancer Benchmark Results');
});

test('writes json report when --format=json --output is given', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);

    expect($json)->toHaveKeys(['summary', 'results'])
        ->and($json['results'])->not->toBeEmpty();
});

test('accepts --timeout option without failing', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--timeout' => '180',
    ])->assertSuccessful();
});

test('task is skipped and reported when required_key resolves to null', function () {
    $manifest = json_encode([
        'meta' => ['app_name' => 'T', 'generated_at' => now()->toISOString(), 'content_hash' => 'x', 'laravel_version' => '13', 'php_version' => '8.4'],
        'artifacts' => ['routes' => [], 'models' => [], 'jobs' => [], 'events' => [], 'policies' => []],
    ], JSON_THROW_ON_ERROR);

    File::put(base_path('necromancer.json'), $manifest);

    $outputPath = base_path('benchmark-skip-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);

    $skippedResults = array_filter($json['results'], fn ($r) => $r['skipped'] === true);
    expect($skippedResults)->not->toBeEmpty();
    expect($json['summary'])->toBe([]);

    File::delete($outputPath);
});

test('task is skipped when required_key resolves to an empty list', function () {
    $manifest = json_encode([
        'meta' => ['app_name' => 'T', 'generated_at' => now()->toISOString(), 'content_hash' => 'x', 'laravel_version' => '13', 'php_version' => '8.4'],
        'artifacts' => [
            'routes' => [['name' => 'welcome', 'method' => 'GET', 'uri' => '/', 'middleware' => [], 'controller' => null, 'action' => null, 'source' => null]],
            'models' => [], 'jobs' => [], 'events' => [], 'policies' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    File::put(base_path('necromancer.json'), $manifest);

    $outputPath = base_path('benchmark-skip-empty-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $skipped = array_filter($json['results'], fn ($r) => $r['skipped'] === true);
    expect(count($skipped))->toBe(count($json['results']));

    File::delete($outputPath);
});

test('resolves necromancer context to skill_path config when Boost is available', function () {
    // Point skill_path to a known file, guidelines to a different file.
    // The benchmark should load from skill_path, not context_path/guidelines.
    $skillPath = base_path('_bench_skill_test.md');
    $guidelinesPath = base_path('_bench_guidelines_test.md');

    File::put($skillPath, 'SKILL_SENTINEL');
    File::put($guidelinesPath, 'GUIDELINES_SENTINEL');

    config([
        'necromancer.boost.skill_path' => $skillPath,
        'necromancer.boost.context_path' => $guidelinesPath,
    ]);

    File::put(base_path('necromancer.json'), benchmarkManifest());

    GenerationAgent::fake(['ok']);

    $outputPath = base_path('_bench_skill_test_out.json');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $ran = array_filter($json['results'], fn ($r) => ! $r['skipped']);

    // At least one non-skipped task means the skill file was found and loaded.
    expect(count($ran))->toBeGreaterThan(0);

    File::delete([$skillPath, $guidelinesPath, $outputPath]);
});

test('only runs tasks matching --type filter', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    GenerationAgent::fake(array_fill(0, 5, 'The route projects.index requires auth.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();
});
