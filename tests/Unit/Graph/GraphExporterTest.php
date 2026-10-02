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

test('export() embeds the graph data directly in graph.html so it works over file:// with no network fetch', function () {
    $output = graphTempDir().'/graph';

    $manifest = completeGraphManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $html = file_get_contents($output.'/graph.html');

    expect($html)->toContain('id="graph-data"')
        ->and($html)->toContain('jobs:App\\\\Jobs\\\\SendInvoice')
        ->and($html)->not->toContain('fetch(');
});

test('export() embeds Select all / Select none controls that toggle every sidebar kind', function () {
    $output = graphTempDir().'/graph';

    $manifest = completeGraphManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $html = file_get_contents($output.'/graph.html');

    expect($html)->toContain('id="select-all-kinds"')
        ->and($html)->toContain('id="select-none-kinds"')
        ->and($html)->toContain('kindCheckboxes.push({ checkbox: checkbox, kind: kind });')
        ->and($html)->toContain('hiddenKinds[entry.kind] = false;')
        ->and($html)->toContain('hiddenKinds[entry.kind] = true;');
});

test('export() embeds an inspect-panel Relationships section listing each edge type touching the selected node', function () {
    $output = graphTempDir().'/graph';

    $manifest = completeGraphManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $html = file_get_contents($output.'/graph.html');

    expect($html)->toContain('id="inspect-relationships"')
        ->and($html)->toContain('<h3>Relationships</h3>')
        ->and($html)->toContain('function relationshipLines(n)')
        ->and($html)->toContain('e.type');
});

test('export() embeds Zoom in / Zoom out controls wired to the shared zoom helper', function () {
    $output = graphTempDir().'/graph';

    $manifest = completeGraphManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $html = file_get_contents($output.'/graph.html');

    expect($html)->toContain('id="zoom-in"')
        ->and($html)->toContain('id="zoom-out"')
        ->and($html)->toContain('function zoomBy(factor, cx, cy)')
        ->and($html)->toContain('zoomBy(0.9, vb.x + vb.w / 2, vb.y + vb.h / 2);')
        ->and($html)->toContain('zoomBy(1.1, vb.x + vb.w / 2, vb.y + vb.h / 2);');
});

test('export() writes derived edges to graph.json for an annotated manifest', function () {
    $output = graphTempDir().'/graph';

    $manifest = completeGraphManifest([
        'jobs' => [[
            'id' => 'jobs:App\\Jobs\\SendInvoice',
            'class' => 'App\\Jobs\\SendInvoice',
            'annotations' => ['domain' => 'billing'],
            'source' => null,
        ]],
    ]);

    (new GraphExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $decoded = json_decode(file_get_contents($output.'/graph.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['edges'])->toBe([
        ['from' => 'jobs:App\\Jobs\\SendInvoice', 'to' => 'domain:billing', 'type' => 'belongs_to_domain', 'kind' => 'grouping', 'provenance' => ['annotation'], 'resolved' => true, 'metadata' => []],
    ]);
});

test('export() writes a byte-identical graph.json when re-run on an unchanged manifest', function () {
    $first = graphTempDir().'/graph';
    $second = graphTempDir().'/graph';
    $manifest = completeGraphManifest([
        'routes' => [['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'action' => 'index', 'middleware' => ['web', 'auth'], 'source' => null]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy', 'annotations' => ['domain' => 'orders'], 'source' => null]],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order', 'source' => null]],
    ]);

    (new GraphExporter)->export($manifest, $first, stale: false, allowStale: false, allowPartial: false);
    (new GraphExporter)->export($manifest, $second, stale: false, allowStale: false, allowPartial: false);

    expect(file_get_contents($second.'/graph.json'))->toBe(file_get_contents($first.'/graph.json'))
        ->and(file_get_contents($first.'/graph.json'))->toContain('"metadata": {}');
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
