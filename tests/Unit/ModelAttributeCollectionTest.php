<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\ModelCollector;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('model-attributes');

function modelFixtureArtifact(string $class): ?array
{
    $collector = new ModelCollector(
        app: app(),
        roots: [[
            'path' => __DIR__.'/../Fixtures/Models',
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Models\\',
        ]],
    );

    foreach ($collector->collect() as $artifact) {
        $data = $artifact->jsonSerialize();
        if ($data['class'] === $class) {
            return $data;
        }
    }

    return null;
}

test('ModelCollector reads #[ObservedBy] into observers field', function () {
    $data = modelFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Models\\OrderWithAttributes');
    expect($data)->not->toBeNull()
        ->and($data['observers'])->toContain('LaravelNecromancer\Tests\Fixtures\Observers\OrderObserver');
});

test('ModelCollector reads #[ScopedBy] into global_scopes field', function () {
    $data = modelFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Models\\OrderWithAttributes');
    expect($data['global_scopes'])->toContain('LaravelNecromancer\Tests\Fixtures\Scopes\ActiveScope');
});

test('ModelCollector reads #[UsePolicy] into policy field', function () {
    $data = modelFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Models\\OrderWithAttributes');
    expect($data['policy'])->toBe('LaravelNecromancer\Tests\Fixtures\Policies\OrderPolicy');
});

test('ModelCollector reads #[UseFactory] into factory field', function () {
    $data = modelFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Models\\OrderWithAttributes');
    expect($data['factory'])->toBe('LaravelNecromancer\Tests\Fixtures\Factories\OrderFactory');
});

test('ModelCollector reads #[UseEloquentBuilder] into custom_builder field', function () {
    $data = modelFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Models\\OrderWithAttributes');
    expect($data['custom_builder'])->toBe('LaravelNecromancer\Tests\Fixtures\Builders\OrderBuilder');
});

test('ModelCollector detects #[Scope]-annotated method in scopes alongside scopeX convention', function () {
    $data = modelFixtureArtifact('LaravelNecromancer\\Tests\\Fixtures\\Models\\OrderWithAttributes');
    expect($data['scopes'])->toContain('pending')
        ->and($data['scopes'])->toContain('verified');
});

test('ModelPayload omits observers from JSON when none declared', function () {
    $artifact = \LaravelNecromancer\Manifest\StructuralArtifact::model(
        class: 'App\\Models\\Order',
        table: 'orders',
    );
    expect($artifact->jsonSerialize())->not->toHaveKey('observers')
        ->and($artifact->jsonSerialize())->not->toHaveKey('global_scopes')
        ->and($artifact->jsonSerialize())->not->toHaveKey('policy');
});
