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
