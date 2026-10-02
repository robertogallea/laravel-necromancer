<?php

use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipResolver;

/**
 * Resolves a manifest's artifacts and returns the serialized Relationships,
 * optionally narrowed to one type.
 *
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 * @return list<array<string, mixed>>
 */
function relationshipsOf(array $artifacts, ?string $type = null): array
{
    $relationships = (new RelationshipResolver)->resolve(['artifacts' => $artifacts]);

    if ($type !== null) {
        $relationships = array_filter($relationships, fn (Relationship $r): bool => $r->type->value === $type);
    }

    return array_values(array_map(fn (Relationship $r): array => $r->jsonSerialize(), $relationships));
}

test('a route is handled_by its collected controller', function () {
    $relationships = relationshipsOf([
        'routes' => [['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'action' => 'index']],
        'controllers' => [['id' => 'controllers:App\\Http\\Controllers\\OrderController', 'class' => 'App\\Http\\Controllers\\OrderController']],
    ], 'handled_by');

    expect($relationships)->toBe([[
        'from' => 'routes:GET:orders',
        'type' => 'handled_by',
        'to' => 'controllers:App\\Http\\Controllers\\OrderController',
        'provenance' => ['runtime'],
        'resolved' => true,
        'metadata' => ['action' => 'index'],
    ]]);
});

test('a route handled_by an uncollected controller is unresolved and keeps the raw class', function () {
    $relationships = relationshipsOf([
        'routes' => [['id' => 'routes:GET:redirect', 'method' => 'GET', 'uri' => 'redirect', 'controller' => 'Illuminate\\Routing\\RedirectController', 'action' => '__invoke']],
    ], 'handled_by');

    expect($relationships)->toBe([[
        'from' => 'routes:GET:redirect',
        'type' => 'handled_by',
        'to' => 'Illuminate\\Routing\\RedirectController',
        'provenance' => ['runtime'],
        'resolved' => false,
        'metadata' => ['action' => '__invoke'],
    ]]);
});

/**
 * @param  list<string>  $middleware
 * @return array<string, mixed>
 */
function middlewareRoute(array $middleware): array
{
    return ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'middleware' => $middleware];
}

test('a route uses_middleware resolves an alias to its alias registration and leaves vendor middleware unresolved', function () {
    $relationships = relationshipsOf([
        'routes' => [middlewareRoute(['auth', 'throttle:60,1'])],
        'middleware' => [
            ['id' => 'middleware:alias:auth', 'alias' => 'auth', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'alias', 'group' => null],
        ],
    ], 'uses_middleware');

    expect($relationships)->toBe([
        [
            'from' => 'routes:GET:orders',
            'type' => 'uses_middleware',
            'to' => 'middleware:alias:auth',
            'provenance' => ['runtime'],
            'resolved' => true,
            'metadata' => ['groups' => [], 'direct' => true],
        ],
        [
            'from' => 'routes:GET:orders',
            'type' => 'uses_middleware',
            'to' => 'throttle:60,1',
            'provenance' => ['runtime'],
            'resolved' => false,
            'metadata' => ['groups' => [], 'direct' => true],
        ],
    ]);
});

test('a route uses_middleware expands a group name to one relationship per member', function () {
    $relationships = relationshipsOf([
        'routes' => [middlewareRoute(['web'])],
        'middleware' => [
            ['id' => 'middleware:group:web:App\\Http\\Middleware\\EncryptCookies', 'alias' => 'EncryptCookies', 'class' => 'App\\Http\\Middleware\\EncryptCookies', 'scope' => 'group', 'group' => 'web'],
            ['id' => 'middleware:group:web:App\\Http\\Middleware\\TrimStrings', 'alias' => 'TrimStrings', 'class' => 'App\\Http\\Middleware\\TrimStrings', 'scope' => 'group', 'group' => 'web'],
            ['id' => 'middleware:group:api:App\\Http\\Middleware\\TrimStrings', 'alias' => 'TrimStrings', 'class' => 'App\\Http\\Middleware\\TrimStrings', 'scope' => 'group', 'group' => 'api'],
        ],
    ], 'uses_middleware');

    expect(array_column($relationships, 'to'))->toBe([
        'middleware:group:web:App\\Http\\Middleware\\EncryptCookies',
        'middleware:group:web:App\\Http\\Middleware\\TrimStrings',
    ])->and(array_column($relationships, 'metadata'))->toBe([
        ['groups' => ['web'], 'direct' => false],
        ['groups' => ['web'], 'direct' => false],
    ])->and(array_column($relationships, 'resolved'))->toBe([true, true]);
});

test('a middleware reached directly and via a group yields one uses_middleware reflecting both paths', function () {
    $relationships = relationshipsOf([
        'routes' => [middlewareRoute(['web', 'App\\Http\\Middleware\\EncryptCookies'])],
        'middleware' => [
            ['id' => 'middleware:global:App\\Http\\Middleware\\EncryptCookies', 'alias' => 'EncryptCookies', 'class' => 'App\\Http\\Middleware\\EncryptCookies', 'scope' => 'global', 'group' => null],
            ['id' => 'middleware:group:web:App\\Http\\Middleware\\EncryptCookies', 'alias' => 'EncryptCookies', 'class' => 'App\\Http\\Middleware\\EncryptCookies', 'scope' => 'group', 'group' => 'web'],
        ],
    ], 'uses_middleware');

    expect($relationships)->toBe([[
        'from' => 'routes:GET:orders',
        'type' => 'uses_middleware',
        'to' => 'middleware:group:web:App\\Http\\Middleware\\EncryptCookies',
        'provenance' => ['runtime'],
        'resolved' => true,
        'metadata' => ['groups' => ['web'], 'direct' => true],
    ]]);
});

test('a directly referenced middleware class with no group path prefers its alias registration', function () {
    $relationships = relationshipsOf([
        'routes' => [middlewareRoute(['App\\Http\\Middleware\\Authenticate'])],
        'middleware' => [
            ['id' => 'middleware:alias:auth', 'alias' => 'auth', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'alias', 'group' => null],
            ['id' => 'middleware:group:web:App\\Http\\Middleware\\Authenticate', 'alias' => 'Authenticate', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'group', 'group' => 'web'],
        ],
    ], 'uses_middleware');

    expect(array_column($relationships, 'to'))->toBe(['middleware:alias:auth']);
});

test('a route validates_with the form request its controller action accepts', function () {
    $relationships = relationshipsOf([
        'routes' => [['id' => 'routes:POST:orders', 'method' => 'POST', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'action' => 'store']],
        'controllers' => [[
            'id' => 'controllers:App\\Http\\Controllers\\OrderController',
            'class' => 'App\\Http\\Controllers\\OrderController',
            'actions' => [
                ['name' => 'index', 'parameters' => [['name' => 'request', 'type' => 'App\\Http\\Requests\\ListOrdersRequest']]],
                ['name' => 'store', 'parameters' => [
                    ['name' => 'request', 'type' => 'App\\Http\\Requests\\StoreOrderRequest'],
                    ['name' => 'order', 'type' => 'App\\Models\\Order'],
                ]],
            ],
        ]],
        'form_requests' => [
            ['id' => 'form_requests:App\\Http\\Requests\\ListOrdersRequest', 'class' => 'App\\Http\\Requests\\ListOrdersRequest'],
            ['id' => 'form_requests:App\\Http\\Requests\\StoreOrderRequest', 'class' => 'App\\Http\\Requests\\StoreOrderRequest'],
        ],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order']],
    ], 'validates_with');

    expect($relationships)->toBe([[
        'from' => 'routes:POST:orders',
        'type' => 'validates_with',
        'to' => 'form_requests:App\\Http\\Requests\\StoreOrderRequest',
        'provenance' => ['reflection'],
        'resolved' => true,
        'metadata' => [],
    ]]);
});

test('a route is authorized_by the policy of each authorized model, once per ability', function () {
    $relationships = relationshipsOf([
        'routes' => [['id' => 'routes:PUT:orders/{order}', 'method' => 'PUT', 'uri' => 'orders/{order}', 'authorization' => [
            ['ability' => 'view', 'models' => ['App\\Models\\Order']],
            ['ability' => 'update', 'models' => ['App\\Models\\Order']],
        ]]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy']],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order']],
    ], 'authorized_by');

    $routeRelationships = array_values(array_filter($relationships, fn (array $r): bool => $r['from'] === 'routes:PUT:orders/{order}'));

    expect($routeRelationships)->toBe([
        [
            'from' => 'routes:PUT:orders/{order}',
            'type' => 'authorized_by',
            'to' => 'policies:App\\Policies\\OrderPolicy',
            'provenance' => ['reflection'],
            'resolved' => true,
            'metadata' => ['ability' => 'view', 'models' => ['App\\Models\\Order']],
        ],
        [
            'from' => 'routes:PUT:orders/{order}',
            'type' => 'authorized_by',
            'to' => 'policies:App\\Policies\\OrderPolicy',
            'provenance' => ['reflection'],
            'resolved' => true,
            'metadata' => ['ability' => 'update', 'models' => ['App\\Models\\Order']],
        ],
    ]);
});

test('a route authorization with no model policy falls back to a gate, else stays unresolved', function () {
    $relationships = relationshipsOf([
        'routes' => [['id' => 'routes:GET:admin', 'method' => 'GET', 'uri' => 'admin', 'authorization' => [
            ['ability' => 'access-admin', 'models' => []],
            ['ability' => 'export', 'models' => ['App\\Models\\Report']],
        ]]],
        'gates' => [['id' => 'gates:ability:access-admin', 'ability' => 'access-admin', 'kind' => 'closure']],
    ], 'authorized_by');

    expect($relationships)->toBe([
        [
            'from' => 'routes:GET:admin',
            'type' => 'authorized_by',
            'to' => 'gates:ability:access-admin',
            'provenance' => ['reflection'],
            'resolved' => true,
            'metadata' => ['ability' => 'access-admin', 'models' => []],
        ],
        [
            'from' => 'routes:GET:admin',
            'type' => 'authorized_by',
            'to' => 'export',
            'provenance' => ['reflection'],
            'resolved' => false,
            'metadata' => ['ability' => 'export', 'models' => ['App\\Models\\Report']],
        ],
    ]);
});

test('a model relates_to another model once per Eloquent method', function () {
    $relationships = relationshipsOf([
        'models' => [
            ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'relationships' => [
                ['type' => 'belongsTo', 'related' => 'App\\Models\\User', 'method' => 'customer'],
                ['type' => 'belongsTo', 'related' => 'App\\Models\\User', 'method' => 'approver'],
                ['type' => 'hasMany', 'related' => 'App\\Models\\OrderLine', 'method' => 'lines'],
            ]],
            ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User'],
        ],
    ], 'relates_to');

    expect($relationships)->toBe([
        ['from' => 'models:App\\Models\\Order', 'type' => 'relates_to', 'to' => 'models:App\\Models\\User', 'provenance' => ['runtime'], 'resolved' => true, 'metadata' => ['method' => 'customer', 'kind' => 'belongsTo']],
        ['from' => 'models:App\\Models\\Order', 'type' => 'relates_to', 'to' => 'models:App\\Models\\User', 'provenance' => ['runtime'], 'resolved' => true, 'metadata' => ['method' => 'approver', 'kind' => 'belongsTo']],
        ['from' => 'models:App\\Models\\Order', 'type' => 'relates_to', 'to' => 'App\\Models\\OrderLine', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => ['method' => 'lines', 'kind' => 'hasMany']],
    ]);
});

test('a model observed_by its observer yields one relationship from either or both ends', function () {
    $bothEnds = relationshipsOf([
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'observers' => ['App\\Observers\\OrderObserver']]],
        'observers' => [['id' => 'observers:App\\Observers\\OrderObserver', 'class' => 'App\\Observers\\OrderObserver', 'model' => 'App\\Models\\Order']],
    ], 'observed_by');

    $observerOnly = relationshipsOf([
        'observers' => [['id' => 'observers:App\\Observers\\OrderObserver', 'class' => 'App\\Observers\\OrderObserver', 'model' => 'App\\Models\\Order']],
    ], 'observed_by');

    expect($bothEnds)->toBe([
        ['from' => 'models:App\\Models\\Order', 'type' => 'observed_by', 'to' => 'observers:App\\Observers\\OrderObserver', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => []],
    ])->and($observerOnly)->toBe([
        ['from' => 'App\\Models\\Order', 'type' => 'observed_by', 'to' => 'observers:App\\Observers\\OrderObserver', 'provenance' => ['reflection'], 'resolved' => false, 'metadata' => []],
    ]);
});

test('a model authorized_by its policy is flagged heuristic only when the guessed policy model is the sole evidence', function () {
    $relationships = relationshipsOf([
        'models' => [
            ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy'],
            ['id' => 'models:App\\Models\\Invoice', 'class' => 'App\\Models\\Invoice', 'policy' => 'App\\Policies\\InvoicePolicy'],
            ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User', 'policy' => null],
        ],
        'policies' => [
            ['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order'],
            ['id' => 'policies:App\\Policies\\InvoicePolicy', 'class' => 'App\\Policies\\InvoicePolicy', 'model' => null],
            ['id' => 'policies:App\\Policies\\UserPolicy', 'class' => 'App\\Policies\\UserPolicy', 'model' => 'App\\Models\\User'],
        ],
    ], 'authorized_by');

    expect($relationships)->toBe([
        ['from' => 'models:App\\Models\\Order', 'type' => 'authorized_by', 'to' => 'policies:App\\Policies\\OrderPolicy', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => []],
        ['from' => 'models:App\\Models\\Invoice', 'type' => 'authorized_by', 'to' => 'policies:App\\Policies\\InvoicePolicy', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => []],
        ['from' => 'models:App\\Models\\User', 'type' => 'authorized_by', 'to' => 'policies:App\\Policies\\UserPolicy', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => ['heuristic' => true]],
    ]);
});

test('an event listened_by its listener yields one relationship from either or both ends', function () {
    $relationships = relationshipsOf([
        'events' => [['id' => 'events:App\\Events\\OrderPlaced', 'class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendConfirmation', 'App\\Listeners\\NotifyWarehouse']]],
        'listeners' => [
            ['id' => 'listeners:App\\Listeners\\SendConfirmation', 'class' => 'App\\Listeners\\SendConfirmation', 'handles' => ['App\\Events\\OrderPlaced']],
            ['id' => 'listeners:App\\Listeners\\LogLogin', 'class' => 'App\\Listeners\\LogLogin', 'handles' => ['Illuminate\\Auth\\Events\\Login']],
        ],
    ], 'listened_by');

    expect($relationships)->toBe([
        ['from' => 'events:App\\Events\\OrderPlaced', 'type' => 'listened_by', 'to' => 'listeners:App\\Listeners\\SendConfirmation', 'provenance' => ['runtime'], 'resolved' => true, 'metadata' => []],
        ['from' => 'events:App\\Events\\OrderPlaced', 'type' => 'listened_by', 'to' => 'App\\Listeners\\NotifyWarehouse', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
        ['from' => 'Illuminate\\Auth\\Events\\Login', 'type' => 'listened_by', 'to' => 'listeners:App\\Listeners\\LogLogin', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
    ]);
});

test('an action operates_on the class types its entrypoints accept', function () {
    $relationships = relationshipsOf([
        'actions' => [['id' => 'actions:App\\Actions\\CancelOrder', 'class' => 'App\\Actions\\CancelOrder', 'entrypoints' => [
            ['name' => 'handle', 'parameters' => [
                ['name' => 'order', 'type' => 'App\\Models\\Order'],
                ['name' => 'reason', 'type' => '?string'],
                ['name' => 'actor', 'type' => 'App\\Models\\User|App\\Models\\Admin|null'],
            ]],
            ['name' => 'undo', 'parameters' => [['name' => 'order', 'type' => '?App\\Models\\Order']]],
        ]]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order']],
    ], 'operates_on');

    expect($relationships)->toBe([
        ['from' => 'actions:App\\Actions\\CancelOrder', 'type' => 'operates_on', 'to' => 'models:App\\Models\\Order', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => []],
        ['from' => 'actions:App\\Actions\\CancelOrder', 'type' => 'operates_on', 'to' => 'App\\Models\\User', 'provenance' => ['reflection'], 'resolved' => false, 'metadata' => []],
        ['from' => 'actions:App\\Actions\\CancelOrder', 'type' => 'operates_on', 'to' => 'App\\Models\\Admin', 'provenance' => ['reflection'], 'resolved' => false, 'metadata' => []],
    ]);
});

test('an artifact is tested_by a test naming it exactly, or by a namespace subject fanned out to every artifact under it', function () {
    $relationships = relationshipsOf([
        'models' => [
            ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order'],
            ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User'],
        ],
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice']],
        'tests' => [
            ['id' => 'tests:tests/Unit/OrderTest.php', 'file' => 'tests/Unit/OrderTest.php', 'subject' => 'App\\Models\\Order'],
            ['id' => 'tests:tests/Unit/ModelsTest.php', 'file' => 'tests/Unit/ModelsTest.php', 'subject' => 'App\\Models'],
            ['id' => 'tests:tests/Unit/GhostTest.php', 'file' => 'tests/Unit/GhostTest.php', 'subject' => 'App\\Services\\Ghost'],
            ['id' => 'tests:tests/Unit/NoSubjectTest.php', 'file' => 'tests/Unit/NoSubjectTest.php', 'subject' => null],
        ],
    ], 'tested_by');

    expect($relationships)->toBe([
        ['from' => 'models:App\\Models\\Order', 'type' => 'tested_by', 'to' => 'tests:tests/Unit/OrderTest.php', 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['match' => 'exact']],
        ['from' => 'models:App\\Models\\Order', 'type' => 'tested_by', 'to' => 'tests:tests/Unit/ModelsTest.php', 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['match' => 'namespace']],
        ['from' => 'models:App\\Models\\User', 'type' => 'tested_by', 'to' => 'tests:tests/Unit/ModelsTest.php', 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['match' => 'namespace']],
        ['from' => 'App\\Services\\Ghost', 'type' => 'tested_by', 'to' => 'tests:tests/Unit/GhostTest.php', 'provenance' => ['source'], 'resolved' => false, 'metadata' => ['match' => 'exact']],
    ]);
});

test('an artifact is tested_by a test whose source references its class or route name, and a subject match wins the merge', function () {
    $relationships = (new RelationshipResolver)->resolve(['artifacts' => [
        'routes' => [['id' => 'routes:POST:orders', 'method' => 'POST', 'uri' => 'orders', 'name' => 'orders.store']],
        'models' => [
            ['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order'],
            ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User'],
        ],
        'tests' => [[
            'id' => 'tests:tests/Feature/Orders/CreateOrderTest.php',
            'file' => 'tests/Feature/Orders/CreateOrderTest.php',
            'subject' => 'App\\Models\\User',
            'references' => [
                ['kind' => 'class', 'target' => 'App\\Models\\Order'],
                ['kind' => 'class', 'target' => 'App\\Models\\User'],
                ['kind' => 'class', 'target' => 'App\\Services\\Ghost'],
                ['kind' => 'route', 'target' => 'orders.missing'],
                ['kind' => 'route', 'target' => 'orders.store'],
            ],
        ]],
    ]]);
    $testedBy = array_values(array_filter($relationships, fn (Relationship $r): bool => $r->type->value === 'tested_by'));
    $test = 'tests:tests/Feature/Orders/CreateOrderTest.php';

    expect(array_map(fn (Relationship $r): array => $r->jsonSerialize(), $testedBy))->toBe([
        ['from' => 'models:App\\Models\\User', 'type' => 'tested_by', 'to' => $test, 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['match' => 'exact']],
        ['from' => 'models:App\\Models\\Order', 'type' => 'tested_by', 'to' => $test, 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['match' => 'reference']],
        ['from' => 'App\\Services\\Ghost', 'type' => 'tested_by', 'to' => $test, 'provenance' => ['source'], 'resolved' => false, 'metadata' => ['match' => 'reference']],
        ['from' => 'orders.missing', 'type' => 'tested_by', 'to' => $test, 'provenance' => ['source'], 'resolved' => false, 'metadata' => ['match' => 'reference']],
        ['from' => 'routes:POST:orders', 'type' => 'tested_by', 'to' => $test, 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['match' => 'reference']],
    ])->and(array_map(fn ($e): array => [$e->field, $e->value], $testedBy[0]->evidence))->toBe([
        ['subject', 'App\\Models\\User'],
        ['references', 'App\\Models\\User'],
    ]);
});

test('an artifact belongs_to_domain, belongs_to_flow, and references_adr from its annotations', function () {
    $relationships = relationshipsOf([
        'jobs' => [[
            'id' => 'jobs:App\\Jobs\\SendInvoice',
            'class' => 'App\\Jobs\\SendInvoice',
            'annotations' => ['domain' => 'billing', 'flow' => 'invoicing', 'adrs' => ['docs/adr/0004-x.md', 'https://example.com/adr/5']],
        ]],
    ]);

    expect($relationships)->toBe([
        ['from' => 'jobs:App\\Jobs\\SendInvoice', 'type' => 'belongs_to_domain', 'to' => 'domain:billing', 'provenance' => ['annotation'], 'resolved' => true, 'metadata' => []],
        ['from' => 'jobs:App\\Jobs\\SendInvoice', 'type' => 'belongs_to_flow', 'to' => 'flow:invoicing', 'provenance' => ['annotation'], 'resolved' => true, 'metadata' => []],
        ['from' => 'jobs:App\\Jobs\\SendInvoice', 'type' => 'references_adr', 'to' => 'adr:docs/adr/0004-x.md', 'provenance' => ['annotation'], 'resolved' => true, 'metadata' => []],
    ]);
});

test('a route authorization naming a model without a policy still falls back alongside the policies it does resolve', function () {
    $relationships = relationshipsOf([
        'routes' => [['id' => 'routes:GET:reports', 'method' => 'GET', 'uri' => 'reports', 'authorization' => [
            ['ability' => 'view', 'models' => ['App\\Models\\Order', 'App\\Models\\Report']],
        ]]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy']],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order']],
    ], 'authorized_by');

    $routeTargets = array_column(array_filter($relationships, fn (array $r): bool => $r['from'] === 'routes:GET:reports'), 'resolved', 'to');

    expect($routeTargets)->toBe(['policies:App\\Policies\\OrderPolicy' => true, 'view' => false]);
});

test('an artifact dispatches each target once, merging the methods and modes of every dispatch of it', function () {
    $relationships = relationshipsOf([
        'controllers' => [['id' => 'controllers:App\\Http\\Controllers\\OrderController', 'class' => 'App\\Http\\Controllers\\OrderController', 'dispatches' => [
            ['target' => 'App\\Events\\OrderPlaced', 'method' => 'store', 'mode' => null],
            ['target' => 'App\\Jobs\\SendInvoice', 'method' => 'store', 'mode' => 'queued'],
            ['target' => 'App\\Jobs\\SendInvoice', 'method' => 'update', 'mode' => 'sync'],
            ['target' => 'App\\Jobs\\SendInvoice', 'method' => 'update', 'mode' => 'queued'],
            ['target' => 'Vendor\\Billing\\InvoicePaid', 'method' => 'update', 'mode' => null],
        ]]],
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice']],
        'events' => [['id' => 'events:App\\Events\\OrderPlaced', 'class' => 'App\\Events\\OrderPlaced']],
    ], 'dispatches');

    expect($relationships)->toBe([
        ['from' => 'controllers:App\\Http\\Controllers\\OrderController', 'type' => 'dispatches', 'to' => 'events:App\\Events\\OrderPlaced', 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['methods' => ['store'], 'modes' => []]],
        ['from' => 'controllers:App\\Http\\Controllers\\OrderController', 'type' => 'dispatches', 'to' => 'jobs:App\\Jobs\\SendInvoice', 'provenance' => ['source'], 'resolved' => true, 'metadata' => ['methods' => ['store', 'update'], 'modes' => ['queued', 'sync']]],
        ['from' => 'controllers:App\\Http\\Controllers\\OrderController', 'type' => 'dispatches', 'to' => 'Vendor\\Billing\\InvoicePaid', 'provenance' => ['source'], 'resolved' => false, 'metadata' => ['methods' => ['update'], 'modes' => []]],
    ]);
});

test('a binding is resolved_as its concrete class, with provenance from how the concrete was learned', function () {
    $relationships = relationshipsOf([
        'bindings' => [
            ['id' => 'bindings:App\\Contracts\\Gateway', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => 'App\\Services\\StripeGateway', 'concrete_source' => 'class'],
            ['id' => 'bindings:App\\Contracts\\Numbers', 'abstract' => 'App\\Contracts\\Numbers', 'concrete' => 'App\\Actions\\NextNumber', 'concrete_source' => 'attribute'],
            ['id' => 'bindings:App\\Actions\\NextNumber', 'abstract' => 'App\\Actions\\NextNumber', 'concrete' => 'App\\Actions\\NextNumber', 'concrete_source' => 'class'],
            ['id' => 'bindings:App\\Contracts\\Unknown', 'abstract' => 'App\\Contracts\\Unknown', 'concrete' => null, 'concrete_source' => null],
        ],
        'actions' => [['id' => 'actions:App\\Actions\\NextNumber', 'class' => 'App\\Actions\\NextNumber']],
    ], 'resolved_as');

    expect($relationships)->toBe([
        ['from' => 'bindings:App\\Contracts\\Gateway', 'type' => 'resolved_as', 'to' => 'App\\Services\\StripeGateway', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
        ['from' => 'bindings:App\\Contracts\\Numbers', 'type' => 'resolved_as', 'to' => 'actions:App\\Actions\\NextNumber', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => []],
        ['from' => 'bindings:App\\Actions\\NextNumber', 'type' => 'resolved_as', 'to' => 'actions:App\\Actions\\NextNumber', 'provenance' => ['runtime'], 'resolved' => true, 'metadata' => []],
    ]);
});

test('a binding concrete never resolves through the binding fallback, so a self-binding or a bound concrete stays the raw class', function () {
    expect(relationshipsOf([
        'bindings' => [
            ['id' => 'bindings:App\\Services\\Ledger', 'abstract' => 'App\\Services\\Ledger', 'concrete' => 'App\\Services\\Ledger', 'concrete_source' => 'class'],
            ['id' => 'bindings:App\\Contracts\\Books', 'abstract' => 'App\\Contracts\\Books', 'concrete' => 'App\\Services\\Ledger', 'concrete_source' => 'class'],
        ],
    ], 'resolved_as'))->toBe([
        ['from' => 'bindings:App\\Services\\Ledger', 'type' => 'resolved_as', 'to' => 'App\\Services\\Ledger', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
        ['from' => 'bindings:App\\Contracts\\Books', 'type' => 'resolved_as', 'to' => 'App\\Services\\Ledger', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
    ]);
});

test('a binding declared by a provider is registered_by that provider', function () {
    expect(relationshipsOf([
        'bindings' => [
            ['id' => 'bindings:App\\Contracts\\Gateway', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => null, 'concrete_source' => null, 'provider' => 'App\\Providers\\PaymentServiceProvider'],
            ['id' => 'bindings:App\\Contracts\\Other', 'abstract' => 'App\\Contracts\\Other', 'concrete' => null, 'concrete_source' => null, 'provider' => null],
        ],
        'service_providers' => [['id' => 'service_providers:App\\Providers\\PaymentServiceProvider', 'class' => 'App\\Providers\\PaymentServiceProvider']],
    ], 'registered_by'))->toBe([
        ['from' => 'bindings:App\\Contracts\\Gateway', 'type' => 'registered_by', 'to' => 'service_providers:App\\Providers\\PaymentServiceProvider', 'provenance' => ['reflection'], 'resolved' => true, 'metadata' => []],
    ]);
});

test('a class target matching no collected artifact resolves to the binding of that abstract, and a collected artifact still wins', function () {
    $relationships = relationshipsOf([
        'actions' => [['id' => 'actions:App\\Actions\\Charge', 'class' => 'App\\Actions\\Charge', 'entrypoints' => [[
            'name' => 'handle',
            'parameters' => [
                ['name' => 'gateway', 'type' => 'App\\Contracts\\Gateway'],
                ['name' => 'order', 'type' => 'App\\Models\\Order'],
            ],
            'return_type' => 'void',
        ]]]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order']],
        'bindings' => [
            ['id' => 'bindings:App\\Contracts\\Gateway', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => null, 'concrete_source' => null],
            ['id' => 'bindings:App\\Models\\Order', 'abstract' => 'App\\Models\\Order', 'concrete' => null, 'concrete_source' => null],
        ],
        'tests' => [['id' => 'tests:tests/Feature/ChargeTest.php', 'file' => 'tests/Feature/ChargeTest.php', 'references' => [['kind' => 'class', 'target' => 'App\\Contracts\\Gateway']]]],
    ]);

    $targets = array_map(fn (array $r): array => [$r['type'], $r['from'], $r['to'], $r['resolved']], array_values(array_filter(
        $relationships,
        fn (array $r): bool => in_array($r['type'], ['operates_on', 'tested_by'], true),
    )));

    expect($targets)->toBe([
        ['operates_on', 'actions:App\\Actions\\Charge', 'bindings:App\\Contracts\\Gateway', true],
        ['operates_on', 'actions:App\\Actions\\Charge', 'models:App\\Models\\Order', true],
        ['tested_by', 'bindings:App\\Contracts\\Gateway', 'tests:tests/Feature/ChargeTest.php', true],
    ]);
});

test('a contextual binding is consumed_by its consumer and takes_precedence_over the global binding of its abstract', function () {
    $relationships = relationshipsOf([
        'controllers' => [['id' => 'controllers:App\\Http\\Controllers\\ReportController', 'class' => 'App\\Http\\Controllers\\ReportController']],
        'bindings' => [
            ['id' => 'bindings:App\\Contracts\\Gateway', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => null, 'concrete_source' => null],
            ['id' => 'bindings:App\\Contracts\\Gateway@App\\Http\\Controllers\\ReportController', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => null, 'concrete_source' => null, 'consumer' => 'App\\Http\\Controllers\\ReportController'],
            ['id' => 'bindings:App\\Contracts\\Mailer@App\\Services\\Reporter', 'abstract' => 'App\\Contracts\\Mailer', 'concrete' => null, 'concrete_source' => null, 'consumer' => 'App\\Services\\Reporter'],
        ],
    ]);

    expect(array_values(array_filter($relationships, fn (array $r): bool => in_array($r['type'], ['consumed_by', 'takes_precedence_over'], true))))->toBe([
        ['from' => 'bindings:App\\Contracts\\Gateway@App\\Http\\Controllers\\ReportController', 'type' => 'consumed_by', 'to' => 'controllers:App\\Http\\Controllers\\ReportController', 'provenance' => ['runtime'], 'resolved' => true, 'metadata' => []],
        ['from' => 'bindings:App\\Contracts\\Gateway@App\\Http\\Controllers\\ReportController', 'type' => 'takes_precedence_over', 'to' => 'bindings:App\\Contracts\\Gateway', 'provenance' => ['runtime'], 'resolved' => true, 'metadata' => []],
        ['from' => 'bindings:App\\Contracts\\Mailer@App\\Services\\Reporter', 'type' => 'consumed_by', 'to' => 'App\\Services\\Reporter', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
        ['from' => 'bindings:App\\Contracts\\Mailer@App\\Services\\Reporter', 'type' => 'takes_precedence_over', 'to' => 'App\\Contracts\\Mailer', 'provenance' => ['runtime'], 'resolved' => false, 'metadata' => []],
    ]);
});

test('a class target resolves only to the global binding of its abstract, never to a contextual one', function () {
    $action = fn (string $class, string $type): array => ['id' => "actions:{$class}", 'class' => $class, 'entrypoints' => [[
        'name' => 'handle',
        'parameters' => [['name' => 'dependency', 'type' => $type]],
        'return_type' => 'void',
    ]]];

    $relationships = relationshipsOf([
        'actions' => [
            $action('App\\Actions\\Charge', 'App\\Contracts\\Gateway'),
            $action('App\\Actions\\Notify', 'App\\Contracts\\Mailer'),
        ],
        'bindings' => [
            ['id' => 'bindings:App\\Contracts\\Gateway', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => null, 'concrete_source' => null],
            ['id' => 'bindings:App\\Contracts\\Gateway@App\\Actions\\Charge', 'abstract' => 'App\\Contracts\\Gateway', 'concrete' => null, 'concrete_source' => null, 'consumer' => 'App\\Actions\\Charge'],
            ['id' => 'bindings:App\\Contracts\\Mailer@App\\Actions\\Notify', 'abstract' => 'App\\Contracts\\Mailer', 'concrete' => null, 'concrete_source' => null, 'consumer' => 'App\\Actions\\Notify'],
        ],
    ], 'operates_on');

    expect(array_map(fn (array $r): array => [$r['from'], $r['to'], $r['resolved']], $relationships))->toBe([
        ['actions:App\\Actions\\Charge', 'bindings:App\\Contracts\\Gateway', true],
        ['actions:App\\Actions\\Notify', 'App\\Contracts\\Mailer', false],
    ]);
});
