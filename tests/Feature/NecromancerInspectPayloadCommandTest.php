<?php

use Illuminate\Support\Facades\File;

function inspectManifest(): string
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
            'models' => [
                ['class' => 'App\\Models\\User', 'table' => 'users', 'relationships' => []],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    File::delete(base_path('necromancer.json'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
});

test('the inspect-payload command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:inspect-payload')
        ->assertSuccessful();
});

test('fails when the manifest does not exist', function () {
    $this->artisan('necromancer:inspect-payload')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('default mode shows full JSON in output', function () {
    File::put(base_path('necromancer.json'), inspectManifest());

    $this->artisan('necromancer:inspect-payload')
        ->expectsOutputToContain('"artifacts"')
        ->assertSuccessful();
});

test('privacy mode shows condensed summary in output', function () {
    File::put(base_path('necromancer.json'), inspectManifest());

    $this->artisan('necromancer:inspect-payload', ['--privacy' => true])
        ->expectsOutputToContain('Routes (1)')
        ->assertSuccessful();
});

test('privacy mode does not output raw JSON', function () {
    File::put(base_path('necromancer.json'), inspectManifest());

    $output = $this->artisan('necromancer:inspect-payload', ['--privacy' => true])
        ->assertSuccessful();

    $output->expectsOutputToContain('Routes');
});

test('output contains size metadata in bytes', function () {
    File::put(base_path('necromancer.json'), inspectManifest());

    $this->artisan('necromancer:inspect-payload')
        ->expectsOutputToContain('bytes')
        ->assertSuccessful();
});

test('output contains estimated token count', function () {
    File::put(base_path('necromancer.json'), inspectManifest());

    $this->artisan('necromancer:inspect-payload')
        ->expectsOutputToContain('tokens')
        ->assertSuccessful();
});

test('output lists artifact types with counts', function () {
    File::put(base_path('necromancer.json'), inspectManifest());

    $this->artisan('necromancer:inspect-payload')
        ->expectsOutputToContain('routes (1) · models (1)')
        ->assertSuccessful();
});
