<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use LaravelNecromancer\Inference\CodebaseAnswerAgent;
use LaravelNecromancer\Integrations\AiDetector;

function askCommandManifest(): string
{
    return json_encode([
        'meta' => ['manifest_schema_version' => 1,
            'app_name' => 'TestApp',
            'generated_at' => now()->toISOString(),
            'laravel_version' => '13.0',
            'php_version' => '8.4',
        ],
        'artifacts' => [
            'routes' => [
                ['name' => 'home', 'method' => 'GET', 'uri' => '/', 'middleware' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

function aiAvailableForAsk(): AiDetector
{
    return new AiDetector(ServiceProvider::class);
}

function aiAbsentForAsk(): AiDetector
{
    return new AiDetector('NonExistent\\Ai\\AiServiceProvider');
}

beforeEach(function () {
    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, aiAvailableForAsk());
    CodebaseAnswerAgent::fake(['fake-ai-answer']);

    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the ask command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:ask')
        ->assertSuccessful();
});

test('fails when the manifest does not exist', function () {
    $this->artisan('necromancer:ask', ['question' => 'What routes exist?'])
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('fails when laravel/ai is not installed', function () {
    $this->instance(AiDetector::class, aiAbsentForAsk());
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask', ['question' => 'What routes exist?'])
        ->expectsOutputToContain('laravel/ai')
        ->assertFailed();
});

test('outputs the ai answer when a question is passed as an argument', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask', ['question' => 'What routes exist?'])
        ->expectsOutputToContain('fake-ai-answer')
        ->assertSuccessful();
});

test('prompts interactively when no question argument is provided', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask')
        ->expectsQuestion('Question', 'What routes exist?')
        ->expectsOutputToContain('fake-ai-answer')
        ->assertSuccessful();
});

test('fails when no question is provided interactively', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask')
        ->expectsQuestion('Question', '')
        ->assertFailed();
});

test('passes the provider option to the agent', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask', [
        'question' => 'What routes exist?',
        '--provider' => 'anthropic',
    ])->assertSuccessful();

    CodebaseAnswerAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->contains('What routes exist?');
    });
});

test('passes the model option to the agent', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask', [
        'question' => 'What routes exist?',
        '--model' => 'claude-3-5-sonnet',
    ])->assertSuccessful();
});

test('includes the manifest content in the agent instructions', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask', ['question' => 'What routes exist?'])
        ->assertSuccessful();

    CodebaseAnswerAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->contains('What routes exist?');
    });
});

test('includes a most relevant evidence section ranking matches for the question', function () {
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'app_name' => 'TestApp', 'laravel_version' => '13.0', 'php_version' => '8.4'],
        'artifacts' => [
            'routes' => [
                ['name' => 'home', 'method' => 'GET', 'uri' => '/', 'middleware' => []],
                [
                    'name' => 'billing.cancel', 'method' => 'POST', 'uri' => '/billing/cancel', 'middleware' => [],
                    'route_metadata' => ['necromancer' => ['domain' => 'billing']],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:ask', ['question' => 'How does billing work?'])
        ->assertSuccessful();

    CodebaseAnswerAgent::assertPrompted(function (AgentPrompt $prompt) {
        $instructions = (string) $prompt->agent->instructions();

        return str_contains($instructions, 'Most Relevant Evidence')
            && str_contains($instructions, 'billing.cancel');
    });
});

test('omits the most relevant evidence section when nothing matches the question', function () {
    File::put(base_path('necromancer.json'), askCommandManifest());

    $this->artisan('necromancer:ask', ['question' => 'zzznonexistentkeywordzzz'])
        ->assertSuccessful();

    CodebaseAnswerAgent::assertPrompted(function (AgentPrompt $prompt) {
        return ! str_contains((string) $prompt->agent->instructions(), 'Most Relevant Evidence');
    });
});
