<?php

use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\ImpactNode;

/**
 * Reduces an Impact to comparable rows: id, type, distance, direction, the
 * node that reached it, and the reaching Relationship's type.
 *
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 * @return list<array{id: string, type: ?string, distance: int, direction: string, from: string, relationship: string, resolved: bool}>
 */
function impactOf(array $artifacts, string $start, int $depth = 1): array
{
    $impact = (new ImpactAnalyzer)->analyze(['artifacts' => $artifacts], $start, $depth);

    return array_map(fn (ImpactNode $node): array => [
        'id' => $node->id,
        'type' => $node->type,
        'distance' => $node->distance,
        'direction' => $node->direction->value,
        'from' => $node->viaFrom,
        'relationship' => $node->via->type->value,
        'resolved' => $node->resolved(),
    ], $impact->nodes);
}

/**
 * A model governed by a policy, and a route authorized against that model.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function policedOrderArtifacts(): array
{
    return [
        'routes' => [[
            'id' => 'routes:GET:orders',
            'method' => 'GET',
            'uri' => 'orders',
            'authorization' => [['ability' => 'viewAny', 'models' => ['App\\Models\\Order']]],
        ]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy']],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order']],
    ];
}

test('a model reaches its policy at distance 1 through an outgoing authorized_by', function () {
    expect(impactOf(policedOrderArtifacts(), 'models:App\\Models\\Order'))->toBe([[
        'id' => 'policies:App\\Policies\\OrderPolicy',
        'type' => 'policies',
        'distance' => 1,
        'direction' => 'out',
        'from' => 'models:App\\Models\\Order',
        'relationship' => 'authorized_by',
        'resolved' => true,
    ]]);
});

test('a route authorized against a model is reached at distance 2 through its policy, incoming', function () {
    expect(impactOf(policedOrderArtifacts(), 'models:App\\Models\\Order', depth: 2))->toBe([
        [
            'id' => 'policies:App\\Policies\\OrderPolicy',
            'type' => 'policies',
            'distance' => 1,
            'direction' => 'out',
            'from' => 'models:App\\Models\\Order',
            'relationship' => 'authorized_by',
            'resolved' => true,
        ],
        [
            'id' => 'routes:GET:orders',
            'type' => 'routes',
            'distance' => 2,
            'direction' => 'in',
            'from' => 'policies:App\\Policies\\OrderPolicy',
            'relationship' => 'authorized_by',
            'resolved' => true,
        ],
    ]);
});

/**
 * Two jobs sharing a domain, flow, ADR, and namespace-matched test, and two
 * routes sharing an alias middleware.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function boundaryArtifacts(): array
{
    $annotations = ['domain' => 'billing', 'flow' => 'invoicing', 'adrs' => ['docs/adr/0004-x.md']];

    return [
        'routes' => [
            ['id' => 'routes:GET:invoices', 'method' => 'GET', 'uri' => 'invoices', 'middleware' => ['auth']],
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'middleware' => ['auth']],
        ],
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => $annotations],
            ['id' => 'jobs:App\\Jobs\\VoidInvoice', 'class' => 'App\\Jobs\\VoidInvoice', 'annotations' => $annotations],
        ],
        'tests' => [['id' => 'tests:tests/Unit/JobsTest.php', 'file' => 'tests/Unit/JobsTest.php', 'subject' => 'App\\Jobs']],
        'middleware' => [['id' => 'middleware:alias:auth', 'alias' => 'auth', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'alias', 'group' => null]],
    ];
}

test('domain, flow, ADR, and test nodes are reported with their type but never expanded', function () {
    $impact = impactOf(boundaryArtifacts(), 'jobs:App\\Jobs\\SendInvoice', depth: 3);

    expect(array_map(fn (array $node): array => [$node['id'], $node['type'], $node['distance']], $impact))->toBe([
        ['domain:billing', 'domain', 1],
        ['flow:invoicing', 'flow', 1],
        ['adr:docs/adr/0004-x.md', 'adr', 1],
        ['tests:tests/Unit/JobsTest.php', 'tests', 1],
    ]);
});

test('a middleware node is reported but never expanded', function () {
    $ids = array_column(impactOf(boundaryArtifacts(), 'routes:GET:invoices', depth: 3), 'id');

    expect($ids)->toBe(['middleware:alias:auth']);
});

test('a middleware or test as the start still expands', function () {
    expect(array_column(impactOf(boundaryArtifacts(), 'middleware:alias:auth'), 'id'))->toBe(['routes:GET:invoices', 'routes:GET:orders'])
        ->and(array_column(impactOf(boundaryArtifacts(), 'tests:tests/Unit/JobsTest.php'), 'id'))->toBe(['jobs:App\\Jobs\\SendInvoice', 'jobs:App\\Jobs\\VoidInvoice']);
});

test('an unresolved end is a leaf with a null type and resolved false', function () {
    $artifacts = [
        'routes' => [
            ['id' => 'routes:GET:a', 'method' => 'GET', 'uri' => 'a', 'controller' => 'Illuminate\\Routing\\RedirectController', 'action' => '__invoke'],
            ['id' => 'routes:GET:b', 'method' => 'GET', 'uri' => 'b', 'controller' => 'Illuminate\\Routing\\RedirectController', 'action' => '__invoke'],
        ],
    ];

    expect(impactOf($artifacts, 'routes:GET:a', depth: 3))->toBe([[
        'id' => 'Illuminate\\Routing\\RedirectController',
        'type' => null,
        'distance' => 1,
        'direction' => 'out',
        'from' => 'routes:GET:a',
        'relationship' => 'handled_by',
        'resolved' => false,
    ]]);
});

/**
 * Order relates to Customer and Invoice, both observed by AuditObserver;
 * Customer also relates back to Order.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function diamondArtifacts(): array
{
    return [
        'models' => [
            ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'relationships' => [
                ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => 'customer'],
                ['type' => 'hasOne', 'related' => 'App\\Models\\Invoice', 'method' => 'invoice'],
            ]],
            ['id' => 'models:App\\Models\\Customer', 'class' => 'App\\Models\\Customer', 'observers' => ['App\\Observers\\AuditObserver'], 'relationships' => [
                ['type' => 'hasMany', 'related' => 'App\\Models\\Order', 'method' => 'orders'],
            ]],
            ['id' => 'models:App\\Models\\Invoice', 'class' => 'App\\Models\\Invoice', 'observers' => ['App\\Observers\\AuditObserver']],
        ],
        'observers' => [['id' => 'observers:App\\Observers\\AuditObserver', 'class' => 'App\\Observers\\AuditObserver']],
    ];
}

test('each node is reported once at its shortest distance, through cycles, with ties broken by canonical order', function () {
    $impact = impactOf(diamondArtifacts(), 'models:App\\Models\\Order', depth: 5);

    expect(array_map(fn (array $node): array => [$node['id'], $node['distance'], $node['from'], $node['direction']], $impact))->toBe([
        ['models:App\\Models\\Customer', 1, 'models:App\\Models\\Order', 'out'],
        ['models:App\\Models\\Invoice', 1, 'models:App\\Models\\Order', 'out'],
        ['observers:App\\Observers\\AuditObserver', 2, 'models:App\\Models\\Customer', 'out'],
    ]);
});

test('an unchanged manifest yields an identical Impact', function () {
    $analyzer = new ImpactAnalyzer;
    $manifest = ['artifacts' => diamondArtifacts()];

    expect(json_encode($analyzer->analyze($manifest, 'models:App\\Models\\Order', 5)->nodes))
        ->toBe(json_encode($analyzer->analyze($manifest, 'models:App\\Models\\Order', 5)->nodes));
});
