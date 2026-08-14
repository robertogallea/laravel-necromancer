<?php

use LaravelNecromancer\Okf\ManifestContentHash;

test('resolve() returns the manifest content_hash when present', function () {
    $manifest = ['meta' => ['content_hash' => 'abc123']];

    expect(ManifestContentHash::resolve($manifest))->toBe('abc123');
});

test('resolve() returns null when content_hash is absent', function () {
    $manifest = ['meta' => []];

    expect(ManifestContentHash::resolve($manifest))->toBeNull();
});

test('resolve() returns null when content_hash is an empty string', function () {
    $manifest = ['meta' => ['content_hash' => '']];

    expect(ManifestContentHash::resolve($manifest))->toBeNull();
});

test('resolve() returns null when content_hash is not a string', function () {
    $manifest = ['meta' => ['content_hash' => 123]];

    expect(ManifestContentHash::resolve($manifest))->toBeNull();
});
