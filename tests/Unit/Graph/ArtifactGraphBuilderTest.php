<?php

use LaravelNecromancer\Graph\ArtifactGraph;
use LaravelNecromancer\Graph\ArtifactGraphBuilder;
use LaravelNecromancer\Graph\ArtifactGraphNode;

test('build() returns one node per collected artifact', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph)->toBeInstanceOf(ArtifactGraph::class)
        ->and($graph->nodes)->toHaveCount(2)
        ->and($graph->edges)->toBe([]);
});

test('build() resolves id, kind, and a per-type display label', function () {
    $manifest = ['artifacts' => [
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null],
        ],
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
        'gates' => [
            ['id' => 'gates:ability:edit-post', 'ability' => 'edit-post', 'kind' => 'closure', 'parameters' => [], 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes)->toEqual([
        new ArtifactGraphNode('routes:GET:orders', 'routes', 'GET orders'),
        new ArtifactGraphNode('jobs:App\\Jobs\\SendInvoice', 'jobs', 'App\\Jobs\\SendInvoice'),
        new ArtifactGraphNode('gates:ability:edit-post', 'gates', 'edit-post'),
    ]);
});

test('build() carries resolved annotations on a node when declared', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['domain' => 'billing', 'risk' => 'high'],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes[0]->annotations)->toBe(['domain' => 'billing', 'risk' => 'high']);
});

test('build() defaults a node with no declared annotations to an empty array', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes[0]->annotations)->toBe([]);
});

test('build() orders nodes by canonical artifact type regardless of manifest array order', function () {
    $manifest = ['artifacts' => [
        'service_providers' => [
            ['id' => 'service_providers:App\\Providers\\AppServiceProvider', 'class' => 'App\\Providers\\AppServiceProvider', 'source' => null],
        ],
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null],
        ],
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(array_map(fn (ArtifactGraphNode $node): string => $node->kind, $graph->nodes))
        ->toBe(['routes', 'jobs', 'service_providers']);
});

test('build() preserves each type\'s own manifest ordering', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\B', 'class' => 'App\\Jobs\\B', 'source' => null],
            ['id' => 'jobs:App\\Jobs\\A', 'class' => 'App\\Jobs\\A', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(array_map(fn (ArtifactGraphNode $node): string => $node->id, $graph->nodes))
        ->toBe(['jobs:App\\Jobs\\B', 'jobs:App\\Jobs\\A']);
});

test('build() skips an artifact with no assigned id', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes)->toBe([]);
});

test('build() returns an empty graph for an empty manifest without erroring', function () {
    $graph = (new ArtifactGraphBuilder)->build(['artifacts' => []]);

    expect($graph->nodes)->toBe([])
        ->and($graph->edges)->toBe([]);
});

test('build() is deterministic: identical input produces an identical graph', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null],
        ],
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null],
        ],
    ]];

    $graph1 = (new ArtifactGraphBuilder)->build($manifest);
    $graph2 = (new ArtifactGraphBuilder)->build($manifest);

    expect(json_encode($graph1, JSON_THROW_ON_ERROR))->toBe(json_encode($graph2, JSON_THROW_ON_ERROR));
});

test('build() serializes a node without annotations without an annotations key', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);
    $decoded = json_decode(json_encode($graph, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        'nodes' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'kind' => 'jobs', 'label' => 'App\\Jobs\\SendInvoice'],
        ],
        'edges' => [],
    ]);
});

test('build() serializes a node with annotations including the annotations key', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);
    $decoded = json_decode(json_encode($graph, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['nodes'][0])->toBe([
        'id' => 'jobs:App\\Jobs\\SendInvoice',
        'kind' => 'jobs',
        'label' => 'App\\Jobs\\SendInvoice',
        'annotations' => ['domain' => 'billing'],
    ]);
});
