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

test('only runs tasks matching --type filter', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    GenerationAgent::fake(array_fill(0, 5, 'The route projects.index requires auth.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();
});
