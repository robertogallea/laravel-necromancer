<?php

declare(strict_types=1);

use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

test('rejects an unversioned manifest', function () {
    $path = tempnam(sys_get_temp_dir(), 'necromancer-manifest-');

    file_put_contents($path, json_encode([
        'meta' => ['generated_at' => '2026-01-01T00:00:00+00:00'],
        'artifacts' => [
            'routes' => [['method' => 'GET', 'uri' => 'orders']],
            'models' => [['class' => 'App\\Models\\Order']],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        (new ManifestReader)->read($path);
    } finally {
        unlink($path);
    }
})->throws(ManifestNotFoundException::class);

test('rejects a manifest whose schema version is not 1', function () {
    $path = tempnam(sys_get_temp_dir(), 'necromancer-manifest-');

    file_put_contents($path, json_encode([
        'meta' => ['manifest_schema_version' => 2],
        'artifacts' => [],
    ], JSON_THROW_ON_ERROR));

    try {
        (new ManifestReader)->read($path);
    } finally {
        unlink($path);
    }
})->throws(ManifestNotFoundException::class);

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
