<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use LaravelNecromancer\Inference\CodebaseAnswerAgent;
use LaravelNecromancer\Integrations\AiDetector;

function privacyManifest(): string
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
                ['name' => 'home', 'method' => 'GET', 'uri' => '/', 'middleware' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, new AiDetector(ServiceProvider::class));
    CodebaseAnswerAgent::fake(['fake-ai-answer']);
    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('--privacy flag outputs a condensed payload notice', function () {
    File::put(base_path('necromancer.json'), privacyManifest());

    $this->artisan('necromancer:ask', [
        'question' => 'What routes exist?',
        '--privacy' => true,
    ])
        ->expectsOutputToContain('condensed')
        ->assertSuccessful();
});

test('--privacy flag sends summarized manifest to the agent instead of full JSON', function () {
    File::put(base_path('necromancer.json'), privacyManifest());

    $this->artisan('necromancer:ask', [
        'question' => 'What routes exist?',
        '--privacy' => true,
    ])->assertSuccessful();

    CodebaseAnswerAgent::assertPrompted(function (AgentPrompt $prompt) {
        $instructions = $prompt->agent->instructions();

        return str_contains($instructions, 'Routes (1)') && ! str_contains($instructions, '"artifacts"');
    });
});

test('without --privacy flag the full JSON manifest is sent to the agent', function () {
    File::put(base_path('necromancer.json'), privacyManifest());

    $this->artisan('necromancer:ask', ['question' => 'What routes exist?'])
        ->assertSuccessful();

    CodebaseAnswerAgent::assertPrompted(function (AgentPrompt $prompt) {
        return str_contains($prompt->agent->instructions(), '"artifacts"');
    });
});
