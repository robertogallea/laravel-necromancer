<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use LaravelNecromancer\Inference\CodebaseAnswerAgent;
use LaravelNecromancer\Integrations\AiDetector;

function promptCommandManifest(): string
{
    return json_encode([
        'meta' => [
            'app_name' => 'TestApp',
            'generated_at' => now()->toISOString(),
            'laravel_version' => '13.0',
            'php_version' => '8.4',
        ],
        'artifacts' => [
            'routes' => [
                [
                    'name' => 'login',
                    'method' => 'POST',
                    'uri' => '/login',
                    'middleware' => ['web'],
                    'source' => [
                        'file' => 'routes/web.php',
                        'line' => 10,
                        'line_end' => 12,
                    ],
                ],
            ],
            'models' => [
                [
                    'class' => 'App\\Models\\User',
                    'table' => 'users',
                    'source' => [
                        'file' => 'app/Models/User.php',
                        'line' => 1,
                        'line_end' => 60,
                    ],
                ],
            ],
            'jobs' => [
                [
                    'class' => 'App\\Jobs\\SendWelcome',
                    'queue' => 'emails',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

function aiAvailableForPrompt(): AiDetector
{
    return new AiDetector(ServiceProvider::class);
}

function aiAbsentForPrompt(): AiDetector
{
    return new AiDetector('NonExistent\\Ai\\AiServiceProvider');
}

beforeEach(function () {
    if (! class_exists(AiServiceProvider::class)) {
        $this->markTestSkipped('laravel/ai is not installed');
    }

    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, aiAbsentForPrompt());
    CodebaseAnswerAgent::fake(['reformulated question text']);

    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the prompt command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:prompt')
        ->assertSuccessful();
});

test('fails when the manifest does not exist', function () {
    $this->artisan('necromancer:prompt', ['question' => 'login'])
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('outputs citation for matched artifact with source', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['question' => 'login', '--no-ai' => true])
        ->expectsOutputToContain('routes/web.php:10-12')
        ->assertSuccessful();
});

test('skips artifact without source field in citations', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['question' => 'welcome', '--no-ai' => true])
        ->doesntExpectOutputToContain('App\\Jobs\\SendWelcome')
        ->assertSuccessful();
});

test('emits warning when no artifacts match and succeeds', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['question' => 'zzznomatch', '--no-ai' => true])
        ->expectsOutputToContain('No relevant artifacts found')
        ->assertSuccessful();
});

test('--top=1 limits citations and succeeds', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['question' => 'login', '--no-ai' => true, '--top' => '1'])
        ->assertSuccessful();
});

test('prompts interactively when no question argument is provided', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['--no-ai' => true])
        ->expectsQuestion('Question', 'login')
        ->expectsOutputToContain('routes/web.php')
        ->assertSuccessful();
});

test('fails when no question is provided interactively', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['--no-ai' => true])
        ->expectsQuestion('Question', '')
        ->assertFailed();
});

test('--no-ai skips contextualization even when AI is available', function () {
    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, aiAvailableForPrompt());

    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['question' => 'login', '--no-ai' => true])
        ->assertSuccessful();

    CodebaseAnswerAgent::assertNeverPrompted();
});

test('uses AI contextualization when AI is available and --no-ai is not set', function () {
    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, aiAvailableForPrompt());

    File::put(base_path('necromancer.json'), promptCommandManifest());

    $this->artisan('necromancer:prompt', ['question' => 'login'])
        ->expectsOutputToContain('reformulated question text')
        ->assertSuccessful();
});

test('--output writes prompt to file and does not print to stdout', function () {
    File::put(base_path('necromancer.json'), promptCommandManifest());
    $outputPath = base_path('test-prompt-output.txt');

    $this->artisan('necromancer:prompt', ['question' => 'login', '--no-ai' => true, '--output' => $outputPath])
        ->expectsOutputToContain("Prompt written to {$outputPath}")
        ->doesntExpectOutputToContain('routes/web.php:10-12')
        ->assertSuccessful();

    expect(File::exists($outputPath))->toBeTrue();
    expect(File::get($outputPath))->toContain('routes/web.php:10-12');

    File::delete($outputPath);
});

test('emits warning when matched artifacts have no source and succeeds', function () {
    $manifest = json_encode([
        'meta' => [
            'app_name' => 'TestApp',
            'generated_at' => now()->toISOString(),
            'laravel_version' => '13.0',
            'php_version' => '8.4',
        ],
        'artifacts' => [
            'jobs' => [
                ['class' => 'App\\Jobs\\SendWelcome', 'queue' => 'emails'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    File::put(base_path('necromancer.json'), $manifest);

    $this->artisan('necromancer:prompt', ['question' => 'welcome', '--no-ai' => true])
        ->expectsOutputToContain('no source locations')
        ->assertSuccessful();
});
