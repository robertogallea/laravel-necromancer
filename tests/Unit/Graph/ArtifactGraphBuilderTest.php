<?php

use LaravelNecromancer\Graph\ArtifactGraph;
use LaravelNecromancer\Graph\ArtifactGraphBuilder;
use LaravelNecromancer\Graph\ArtifactGraphEdge;
use LaravelNecromancer\Graph\ArtifactGraphNode;

/**
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function edgeTuples(ArtifactGraph $graph): array
{
    return array_map(
        fn (ArtifactGraphEdge $edge): array => [$edge->from, $edge->to, $edge->type->value, $edge->kind->value],
        $graph->edges,
    );
}

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
        new ArtifactGraphNode('routes:GET:orders', 'routes', 'GET orders', facts: ['method' => 'GET', 'uri' => 'orders']),
        new ArtifactGraphNode('jobs:App\\Jobs\\SendInvoice', 'jobs', 'App\\Jobs\\SendInvoice', facts: ['class' => 'App\\Jobs\\SendInvoice']),
        new ArtifactGraphNode('gates:ability:edit-post', 'gates', 'edit-post', facts: ['ability' => 'edit-post', 'kind' => 'closure', 'parameters' => []]),
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
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'kind' => 'jobs', 'label' => 'App\\Jobs\\SendInvoice', 'facts' => ['class' => 'App\\Jobs\\SendInvoice']],
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
        'facts' => ['class' => 'App\\Jobs\\SendInvoice'],
    ]);
});

test('build() derives one structural edge per relationship, collapsing facts recorded on both ends', function () {
    $manifest = ['artifacts' => [
        'models' => [
            [
                'id' => 'models:App\\Models\\Order',
                'class' => 'App\\Models\\Order',
                'relationships' => [
                    ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => 'customer'],
                ],
                'policy' => 'App\\Policies\\OrderPolicy',
                'observers' => ['App\\Observers\\OrderObserver'],
                'source' => null,
            ],
            ['id' => 'models:App\\Models\\Customer', 'class' => 'App\\Models\\Customer', 'source' => null],
        ],
        'policies' => [
            ['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order', 'source' => null],
        ],
        'observers' => [
            ['id' => 'observers:App\\Observers\\OrderObserver', 'class' => 'App\\Observers\\OrderObserver', 'model' => 'App\\Models\\Order', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph))->toBe([
        ['models:App\\Models\\Order', 'models:App\\Models\\Customer', 'relates_to', 'structural'],
        ['models:App\\Models\\Order', 'policies:App\\Policies\\OrderPolicy', 'authorized_by', 'structural'],
        ['models:App\\Models\\Order', 'observers:App\\Observers\\OrderObserver', 'observed_by', 'structural'],
    ]);
});

test('build() derives structural edges for events, listeners, and routes', function () {
    $manifest = ['artifacts' => [
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'source' => null],
        ],
        'events' => [
            ['id' => 'events:App\\Events\\OrderPlaced', 'class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendOrderConfirmation'], 'source' => null],
        ],
        'listeners' => [
            ['id' => 'listeners:App\\Listeners\\SendOrderConfirmation', 'class' => 'App\\Listeners\\SendOrderConfirmation', 'handles' => ['App\\Events\\OrderPlaced'], 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph))->toBe([
        ['routes:GET:orders', 'App\\Http\\Controllers\\OrderController', 'handled_by', 'structural'],
        ['events:App\\Events\\OrderPlaced', 'listeners:App\\Listeners\\SendOrderConfirmation', 'listened_by', 'structural'],
    ]);
});

test('build() resolves a route controller edge to the collected controller node', function () {
    $manifest = ['artifacts' => [
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'source' => null],
        ],
        'controllers' => [
            ['id' => 'controllers:App\\Http\\Controllers\\OrderController', 'class' => 'App\\Http\\Controllers\\OrderController', 'actions' => [], 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph))->toBe([
        ['routes:GET:orders', 'controllers:App\\Http\\Controllers\\OrderController', 'handled_by', 'structural'],
    ]);
});

test('build() derives one grouping edge per declared domain and one per declared flow', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['domain' => 'billing', 'flow' => 'invoicing'],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph))->toBe([
        ['jobs:App\\Jobs\\SendInvoice', 'domain:billing', 'belongs_to_domain', 'grouping'],
        ['jobs:App\\Jobs\\SendInvoice', 'flow:invoicing', 'belongs_to_flow', 'grouping'],
    ]);
});

test('build() derives one reference edge per declared local adr, skipping absolute-URI adrs', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['adrs' => ['docs/adr/0004-x.md', 'https://example.com/adr/0005']],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph))->toBe([
        ['jobs:App\\Jobs\\SendInvoice', 'adr:docs/adr/0004-x.md', 'references_adr', 'reference'],
    ]);
});

test('build() invents no edges for an artifact with no relationships, annotations, or adrs', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
        'gates' => [
            ['id' => 'gates:ability:edit-post', 'ability' => 'edit-post', 'kind' => 'closure', 'parameters' => [], 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->edges)->toBe([]);
});

test('build() produces zero grouping and reference edges for an unannotated manifest without erroring', function () {
    $manifest = ['artifacts' => [
        'models' => [
            ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->edges)->toBe([]);
});

test('build() orders edges deterministically: canonical artifact-type order, then per artifact structural, domain, flow, and reference, from the resolver', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['domain' => 'billing', 'flow' => 'invoicing', 'adrs' => ['docs/adr/0004-x.md']],
                'source' => null,
            ],
        ],
        'observers' => [
            ['id' => 'observers:App\\Observers\\SyncInvoice', 'class' => 'App\\Observers\\SyncInvoice', 'model' => 'App\\Models\\Order', 'source' => null],
        ],
    ]];

    $graph1 = (new ArtifactGraphBuilder)->build($manifest);
    $graph2 = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph1))->toBe([
        ['jobs:App\\Jobs\\SendInvoice', 'domain:billing', 'belongs_to_domain', 'grouping'],
        ['jobs:App\\Jobs\\SendInvoice', 'flow:invoicing', 'belongs_to_flow', 'grouping'],
        ['jobs:App\\Jobs\\SendInvoice', 'adr:docs/adr/0004-x.md', 'references_adr', 'reference'],
        ['App\\Models\\Order', 'observers:App\\Observers\\SyncInvoice', 'observed_by', 'structural'],
    ])->and($graph1->edges)->toEqual($graph2->edges);
});

test('build() serializes an edge with its from, to, type, kind, provenance, resolved flag, and metadata', function () {
    $manifest = ['artifacts' => [
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'action' => 'index', 'source' => null],
        ],
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['domain' => 'billing'],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);
    $decoded = json_decode(json_encode($graph, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['edges'])->toBe([
        [
            'from' => 'routes:GET:orders',
            'to' => 'App\\Http\\Controllers\\OrderController',
            'type' => 'handled_by',
            'kind' => 'structural',
            'provenance' => ['runtime'],
            'resolved' => false,
            'metadata' => ['action' => 'index'],
        ],
        [
            'from' => 'jobs:App\\Jobs\\SendInvoice',
            'to' => 'domain:billing',
            'type' => 'belongs_to_domain',
            'kind' => 'grouping',
            'provenance' => ['annotation'],
            'resolved' => true,
            'metadata' => [],
        ],
    ]);
});

test('build() adds a domain and a flow node so grouping edges resolve to a visible node', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['domain' => 'billing', 'flow' => 'invoicing'],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes)->toEqual([
        new ArtifactGraphNode('jobs:App\\Jobs\\SendInvoice', 'jobs', 'App\\Jobs\\SendInvoice', ['domain' => 'billing', 'flow' => 'invoicing'], ['class' => 'App\\Jobs\\SendInvoice']),
        new ArtifactGraphNode('domain:billing', 'domain', 'billing'),
        new ArtifactGraphNode('flow:invoicing', 'flow', 'invoicing'),
    ]);
});

test('build() adds an adr node so a reference edge resolves to a visible node', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['adrs' => ['docs/adr/0004-cancel-flow.md']],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes[1])->toEqual(new ArtifactGraphNode('adr:docs/adr/0004-cancel-flow.md', 'adr', '0004-cancel-flow'));
});

test('build() adds only one node per distinct domain shared by multiple artifacts', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\A', 'class' => 'App\\Jobs\\A', 'annotations' => ['domain' => 'billing'], 'source' => null],
        ],
        'events' => [
            ['id' => 'events:App\\Events\\B', 'class' => 'App\\Events\\B', 'annotations' => ['domain' => 'billing'], 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    $domainNodes = array_values(array_filter($graph->nodes, fn (ArtifactGraphNode $n): bool => $n->kind === 'domain'));

    expect($domainNodes)->toHaveCount(1)
        ->and($domainNodes[0])->toEqual(new ArtifactGraphNode('domain:billing', 'domain', 'billing'));
});

test('build() adds no group or adr nodes for an unannotated manifest', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes)->toHaveCount(1);
});

test('build() carries discovered facts, reusing ArtifactConceptBuilder::EXCLUDED_FACT_KEYS to exclude id, annotations, source, and route_metadata', function () {
    $manifest = ['artifacts' => [
        'routes' => [
            [
                'id' => 'routes:GET:orders',
                'method' => 'GET',
                'uri' => 'orders',
                'annotations' => ['domain' => 'billing'],
                'route_metadata' => ['raw' => ['head' => ['title' => 'x']]],
                'source' => ['file' => 'routes/web.php', 'line' => 1],
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect($graph->nodes[0]->facts)->toBe(['method' => 'GET', 'uri' => 'orders']);
});

test('build() gives a synthetic domain, flow, or adr node no facts', function () {
    $manifest = ['artifacts' => [
        'jobs' => [
            [
                'id' => 'jobs:App\\Jobs\\SendInvoice',
                'class' => 'App\\Jobs\\SendInvoice',
                'annotations' => ['domain' => 'billing', 'flow' => 'invoicing', 'adrs' => ['docs/adr/0004-x.md']],
                'source' => null,
            ],
        ],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    $syntheticNodes = array_values(array_filter(
        $graph->nodes,
        fn (ArtifactGraphNode $n): bool => in_array($n->kind, ['domain', 'flow', 'adr'], true),
    ));

    expect($syntheticNodes)->toHaveCount(3)
        ->and(array_map(fn (ArtifactGraphNode $n): array => $n->facts, $syntheticNodes))->toBe([[], [], []]);
});

test('build() derives a behavioral edge per dispatched target without synthesizing a node for it', function () {
    $manifest = ['artifacts' => [
        'actions' => [['id' => 'actions:App\\Actions\\PlaceOrder', 'class' => 'App\\Actions\\PlaceOrder', 'dispatches' => [
            ['target' => 'App\\Jobs\\SendInvoice', 'method' => 'handle', 'mode' => 'queued'],
            ['target' => 'Vendor\\Billing\\InvoicePaid', 'method' => 'handle', 'mode' => null],
        ], 'source' => null]],
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]];

    $graph = (new ArtifactGraphBuilder)->build($manifest);

    expect(edgeTuples($graph))->toBe([
        ['actions:App\\Actions\\PlaceOrder', 'jobs:App\\Jobs\\SendInvoice', 'dispatches', 'behavioral'],
        ['actions:App\\Actions\\PlaceOrder', 'Vendor\\Billing\\InvoicePaid', 'dispatches', 'behavioral'],
    ])->and(array_map(fn (ArtifactGraphNode $node): string => $node->id, $graph->nodes))->toBe([
        'actions:App\\Actions\\PlaceOrder',
        'jobs:App\\Jobs\\SendInvoice',
    ]);
});
