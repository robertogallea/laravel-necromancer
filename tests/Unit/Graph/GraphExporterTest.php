<?php

use LaravelNecromancer\Graph\GraphExporter;

function graphTempDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-graph-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeGraphTree(string $path): void
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

function completeGraphManifest(array $artifacts = []): array
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
    foreach (glob(sys_get_temp_dir().'/necromancer-graph-*') as $dir) {
        removeGraphTree($dir);
    }
});

test('export() writes graph.json and graph.html to the output directory', function () {
    $output = graphTempDir().'/graph';

    $manifest = completeGraphManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    $result = (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->nodeCount)->toBe(1)
        ->and(is_file($output.'/graph.json'))->toBeTrue()
        ->and(is_file($output.'/graph.html'))->toBeTrue();

    $decoded = json_decode(file_get_contents($output.'/graph.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded['nodes'])->toHaveCount(1)
        ->and($decoded['edges'])->toBe([]);
});

test('export() refuses a stale manifest by default', function () {
    $output = graphTempDir().'/graph';

    $result = (new GraphExporter)->export(completeGraphManifest(), $output, stale: true, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('stale')
        ->and(is_dir($output))->toBeFalse();
});

test('export() proceeds on a stale manifest when allowStale is true', function () {
    $output = graphTempDir().'/graph';

    $result = (new GraphExporter)->export(completeGraphManifest(), $output, stale: true, allowStale: true, allowPartial: false);

    expect($result->successful)->toBeTrue();
});

test('export() refuses a partial-scope manifest by default', function () {
    $output = graphTempDir().'/graph';
    $manifest = completeGraphManifest();
    $manifest['meta']['scope']['complete'] = false;

    $result = (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('partial')
        ->and(is_dir($output))->toBeFalse();
});

test('export() proceeds on a partial-scope manifest when allowPartial is true', function () {
    $output = graphTempDir().'/graph';
    $manifest = completeGraphManifest();
    $manifest['meta']['scope']['complete'] = false;

    $result = (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: true);

    expect($result->successful)->toBeTrue();
});

test('export() replaces a previously-generated graph in place', function () {
    $output = graphTempDir().'/graph';
    mkdir($output, 0755, true);
    file_put_contents($output.'/graph.json', '{"nodes":[],"edges":[]}');
    file_put_contents($output.'/graph.html', '<html>old</html>');

    $result = (new GraphExporter)->export(completeGraphManifest([]), $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and(file_get_contents($output.'/graph.html'))->not->toBe('<html>old</html>');
});
