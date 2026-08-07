<?php

declare(strict_types=1);

use LaravelNecromancer\Manifest\ManifestReader;

test('adapts an unversioned manifest in memory with IDs and conservative scope', function () {
    $path = tempnam(sys_get_temp_dir(), 'necromancer-manifest-');

    file_put_contents($path, json_encode([
        'meta' => ['generated_at' => '2026-01-01T00:00:00+00:00'],
        'artifacts' => [
            'routes' => [['method' => 'GET', 'uri' => 'orders']],
            'models' => [['class' => 'App\\Models\\Order']],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $manifest = (new ManifestReader)->read($path);
    } finally {
        unlink($path);
    }

    expect($manifest['meta'])
        ->toMatchArray([
            'manifest_schema_version' => 1,
            'annotation_schema_version' => 1,
            'scope' => [
                'complete' => false,
                'artifact_types' => ['models', 'routes'],
            ],
        ])
        ->and($manifest['artifacts']['routes'][0]['id'])->toBe('routes:GET:orders')
        ->and($manifest['artifacts']['models'][0]['id'])->toBe('models:App\\Models\\Order');
});

test('preserves IDs and resolved content in a current manifest', function () {
    $path = tempnam(sys_get_temp_dir(), 'necromancer-manifest-');
    $firstId = 'scheduled_tasks:'.str_repeat('a', 64).':2';
    $secondId = 'scheduled_tasks:'.str_repeat('a', 64).':10';

    file_put_contents($path, json_encode([
        'meta' => [
            'manifest_schema_version' => 1,
            'annotation_schema_version' => 1,
            'scope' => ['complete' => true, 'artifact_types' => ['scheduled_tasks']],
        ],
        'artifacts' => [
            'scheduled_tasks' => [
                ['id' => $secondId, 'command' => 'inspire', 'expression' => '0 0 * * *'],
                ['id' => $firstId, 'command' => 'inspire', 'expression' => '0 0 * * *'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $manifest = (new ManifestReader)->read($path);
    } finally {
        unlink($path);
    }

    expect($manifest['artifacts']['scheduled_tasks'][0]['id'])->toBe($secondId)
        ->and($manifest['artifacts']['scheduled_tasks'][1]['id'])->toBe($firstId);
});

test('promotes legacy route declarations into the universal annotation shape', function () {
    $path = tempnam(sys_get_temp_dir(), 'necromancer-manifest-');

    file_put_contents($path, json_encode([
        'meta' => [],
        'artifacts' => [
            'routes' => [[
                'method' => 'POST',
                'uri' => 'billing/cancel',
                'route_metadata' => [
                    'raw' => ['necromancer' => ['risk' => 'high', 'adr' => 'docs/adr/004.md']],
                    'necromancer' => ['domain' => 'billing', 'risk' => 'high', 'adr' => 'docs/adr/004.md'],
                ],
            ]],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $manifest = (new ManifestReader)->read($path);
    } finally {
        unlink($path);
    }

    $route = $manifest['artifacts']['routes'][0];

    expect($route['annotations'])->toBe([
        'domain' => 'billing',
        'risk' => 'high',
        'adrs' => ['docs/adr/004.md'],
    ])
        ->and($route['route_metadata']['raw'])->toBe(['necromancer' => ['risk' => 'high', 'adr' => 'docs/adr/004.md']])
        ->and($route['route_metadata']['necromancer']['adrs'])->toBe(['docs/adr/004.md']);
});
