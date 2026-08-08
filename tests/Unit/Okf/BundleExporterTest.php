<?php

use LaravelNecromancer\Okf\BundleExporter;

function okfTempDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-okf-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function completeManifest(array $artifacts = []): array
{
    return [
        'meta' => [
            'generated_at' => '2026-08-07T12:00:00+02:00',
            'scope' => ['complete' => true, 'artifact_types' => array_keys($artifacts)],
        ],
        'artifacts' => $artifacts,
    ];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/necromancer-okf-*') as $dir) {
        removeTree($dir);
    }
});

test('export() writes one file per artifact plus a bundle.json index', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null],
        ],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->artifactCount)->toBe(2)
        ->and(is_dir($output.'/artifacts'))->toBeTrue()
        ->and(is_file($output.'/bundle.json'))->toBeTrue()
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(2);

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index['artifact_count'])->toBe(2)
        ->and($index['generated_at'])->toBe('2026-08-07T12:00:00+02:00');
});

test('export() refuses a stale manifest by default', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    $result = (new BundleExporter)->export($manifest, $output, stale: true, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('stale')
        ->and(is_dir($output))->toBeFalse();
});

test('export() proceeds on a stale manifest when allowStale is true', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    $result = (new BundleExporter)->export($manifest, $output, stale: true, allowStale: true, allowPartial: false);

    expect($result->successful)->toBeTrue();
});

test('export() refuses a partial-scope manifest by default', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);
    $manifest['meta']['scope']['complete'] = false;

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('partial')
        ->and(is_dir($output))->toBeFalse();
});

test('export() treats a manifest with no scope key at all as partial', function () {
    $output = okfTempDir().'/bundle';
    $manifest = ['meta' => ['generated_at' => '2026-08-07T12:00:00+02:00'], 'artifacts' => []];

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse();
});

test('export() proceeds on a partial-scope manifest when allowPartial is true', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);
    $manifest['meta']['scope']['complete'] = false;

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: true);

    expect($result->successful)->toBeTrue();
});

test('export() replaces a pre-existing bundle at the same output path', function () {
    $output = okfTempDir().'/bundle';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/artifacts/stale-concept.md', 'old');
    file_put_contents($output.'/bundle.json', '{"artifact_count": 99}');

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and(is_file($output.'/artifacts/stale-concept.md'))->toBeFalse()
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(1);
});

test('export() leaves an existing bundle untouched when concept building fails', function () {
    $output = okfTempDir().'/bundle';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/artifacts/marker.md', 'do-not-touch');
    file_put_contents($output.'/bundle.json', '{"artifact_count": 1}');

    // Two artifacts with the same id (malformed manifest) resolve to the
    // same concept filename, which the exporter must refuse to overwrite
    // silently — and it must not touch existing real output while doing so.
    $manifest = completeManifest([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and(is_file($output.'/artifacts/marker.md'))->toBeTrue()
        ->and(file_get_contents($output.'/bundle.json'))->toBe('{"artifact_count": 1}');
});

test('export() is deterministic: the same manifest produces byte-identical files across two runs', function () {
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null]],
    ]);

    $outputA = okfTempDir().'/bundle';
    $outputB = okfTempDir().'/bundle';

    (new BundleExporter)->export($manifest, $outputA, stale: false, allowStale: false, allowPartial: false);
    (new BundleExporter)->export($manifest, $outputB, stale: false, allowStale: false, allowPartial: false);

    $filesA = glob($outputA.'/artifacts/*.md');
    $filesB = glob($outputB.'/artifacts/*.md');

    expect(basename($filesA[0]))->toBe(basename($filesB[0]))
        ->and(file_get_contents($filesA[0]))->toBe(file_get_contents($filesB[0]));
});

test('export() succeeds for an empty manifest with zero artifacts', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->artifactCount)->toBe(0)
        ->and(is_dir($output.'/artifacts'))->toBeTrue();
});
