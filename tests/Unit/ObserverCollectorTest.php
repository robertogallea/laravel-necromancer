<?php

declare(strict_types=1);

use LaravelNecromancer\Collection\ObserverCollector;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('observer-collector');

test('ObserverCollector populates model field from the provided model map', function () {
    $observerClass = 'LaravelNecromancer\\Tests\\Fixtures\\Observers\\NecromancerIssueObserver';
    $modelClass = 'App\\Models\\Issue';

    $collector = new ObserverCollector(
        app: app(),
        roots: [[
            'path' => base_path('tests/Fixtures/Observers'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Observers\\',
        ]],
        modelMap: [$observerClass => $modelClass],
    );

    $artifacts = $collector->collect();

    $artifact = collect($artifacts)
        ->first(fn ($a) => $a->jsonSerialize()['class'] === $observerClass);

    expect($artifact)->not->toBeNull()
        ->and($artifact->jsonSerialize()['model'])->toBe($modelClass);
});

test('ObserverCollector skips observers with no lifecycle hooks', function () {
    $collector = new ObserverCollector(
        app: app(),
        roots: [[
            'path' => base_path('tests/Fixtures/Observers'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Observers\\',
        ]],
    );

    $artifacts = $collector->collect();

    $classes = array_map(fn ($a) => $a->jsonSerialize()['class'], $artifacts);

    // OrderObserver is empty (no hooks) and must be filtered out.
    expect($classes)->not->toContain('LaravelNecromancer\\Tests\\Fixtures\\Observers\\OrderObserver');
});
