<?php

use LaravelNecromancer\Relationships\AffectedTest;
use LaravelNecromancer\Relationships\AffectedTestFinder;

/**
 * Order with its policy, a route authorized against it, a controller, and
 * tests of the model, the policy, and the Jobs namespace.
 *
 * @return array<string, mixed>
 */
function affectedTestsManifest(): array
{
    return ['artifacts' => [
        'routes' => [[
            'id' => 'routes:GET:orders',
            'method' => 'GET',
            'uri' => 'orders',
            'controller' => 'App\\Http\\Controllers\\OrderController',
            'action' => 'index',
            'authorization' => [['ability' => 'viewAny', 'models' => ['App\\Models\\Order']]],
            'source' => ['file' => 'app/Http/Controllers/OrderController.php'],
        ]],
        'controllers' => [[
            'id' => 'controllers:App\\Http\\Controllers\\OrderController',
            'class' => 'App\\Http\\Controllers\\OrderController',
            'source' => ['file' => 'app/Http/Controllers/OrderController.php'],
        ]],
        'models' => [[
            'id' => 'models:App\\Models\\Order',
            'class' => 'App\\Models\\Order',
            'policy' => 'App\\Policies\\OrderPolicy',
            'annotations' => ['flow' => 'checkout'],
            'source' => ['file' => 'app/Models/Order.php'],
        ]],
        'jobs' => [[
            'id' => 'jobs:App\\Jobs\\ShipOrder',
            'class' => 'App\\Jobs\\ShipOrder',
            'annotations' => ['flow' => 'checkout'],
            'source' => ['file' => 'app/Jobs/ShipOrder.php'],
        ]],
        'policies' => [[
            'id' => 'policies:App\\Policies\\OrderPolicy',
            'class' => 'App\\Policies\\OrderPolicy',
            'model' => 'App\\Models\\Order',
            'source' => ['file' => 'app/Policies/OrderPolicy.php'],
        ]],
        'tests' => [
            ['id' => 'tests:tests/Unit/OrderTest.php', 'file' => 'tests/Unit/OrderTest.php', 'subject' => 'App\\Models\\Order', 'source' => ['file' => 'tests/Unit/OrderTest.php']],
            ['id' => 'tests:tests/Unit/OrderPolicyTest.php', 'file' => 'tests/Unit/OrderPolicyTest.php', 'subject' => 'App\\Policies\\OrderPolicy', 'source' => ['file' => 'tests/Unit/OrderPolicyTest.php']],
            ['id' => 'tests:tests/Unit/JobsTest.php', 'file' => 'tests/Unit/JobsTest.php', 'subject' => 'App\\Jobs', 'source' => ['file' => 'tests/Unit/JobsTest.php']],
        ],
    ]];
}

/**
 * @param  list<AffectedTest>  $tests
 * @return list<array{string, int, ?string, string, ?string}>
 */
function affectedRows(array $tests): array
{
    return array_map(fn (AffectedTest $test): array => [$test->file, $test->distance, $test->match(), $test->start, $test->viaLabel], $tests);
}

test('a model directly affects its own test and indirectly affects its policy test', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['models:App\\Models\\Order'], 2);

    expect(affectedRows($tests))->toBe([
        ['tests/Unit/OrderTest.php', 1, 'exact', 'models:App\\Models\\Order', 'App\\Models\\Order'],
        ['tests/Unit/OrderPolicyTest.php', 2, 'exact', 'models:App\\Models\\Order', 'App\\Policies\\OrderPolicy'],
    ])
        ->and($tests[0]->directlyAffected())->toBeTrue()
        ->and($tests[1]->directlyAffected())->toBeFalse();
});

test('depth 1 keeps only directly affected tests', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['models:App\\Models\\Order'], 1);

    expect(affectedRows($tests))->toBe([
        ['tests/Unit/OrderTest.php', 1, 'exact', 'models:App\\Models\\Order', 'App\\Models\\Order'],
    ]);
});

test('a namespace-subject test is included with its namespace match', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['jobs:App\\Jobs\\ShipOrder'], 2);

    expect(affectedRows($tests))->toBe([
        ['tests/Unit/JobsTest.php', 1, 'namespace', 'jobs:App\\Jobs\\ShipOrder', 'App\\Jobs\\ShipOrder'],
    ]);
});

test('a test linked only through a shared flow is not reached from an artifact', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['jobs:App\\Jobs\\ShipOrder'], 3);

    expect(array_column(affectedRows($tests), 0))->not->toContain('tests/Unit/OrderTest.php');
});

test('a flow start yields the tests of its members', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['flow:checkout'], 2);

    expect(affectedRows($tests))->toBe([
        ['tests/Unit/OrderTest.php', 2, 'exact', 'flow:checkout', 'App\\Models\\Order'],
        ['tests/Unit/JobsTest.php', 2, 'namespace', 'flow:checkout', 'App\\Jobs\\ShipOrder'],
    ]);
});

test('a test reached from several starts is reported once, at its smallest distance', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['models:App\\Models\\Order', 'policies:App\\Policies\\OrderPolicy'], 2);

    expect(affectedRows($tests))->toBe([
        ['tests/Unit/OrderTest.php', 1, 'exact', 'models:App\\Models\\Order', 'App\\Models\\Order'],
        ['tests/Unit/OrderPolicyTest.php', 1, 'exact', 'policies:App\\Policies\\OrderPolicy', 'App\\Policies\\OrderPolicy'],
    ]);
});

test('a changed test is directly affected on its own, without walking from it', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['tests:tests/Unit/OrderPolicyTest.php', 'models:App\\Models\\Order'], 2);

    expect(affectedRows($tests))->toBe([
        ['tests/Unit/OrderPolicyTest.php', 0, null, 'tests:tests/Unit/OrderPolicyTest.php', null],
        ['tests/Unit/OrderTest.php', 1, 'exact', 'models:App\\Models\\Order', 'App\\Models\\Order'],
    ])
        ->and($tests[0]->directlyAffected())->toBeTrue()
        ->and($tests[0]->node)->toBeNull();
});

test('changed paths are normalized and map to every artifact sharing the source file', function () {
    $mapped = (new AffectedTestFinder)->startsForPaths(affectedTestsManifest(), [
        './app/Models/Order.php',
        '/srv/app/app/Models/Order.php',
        '',
        '  ',
        'app/Http/Controllers/OrderController.php',
        'tests/Unit/JobsTest.php',
        'routes/web.php',
        'routes/web.php',
        'App/Models/Order.php',
    ], '/srv/app');

    expect($mapped)->toBe([
        'starts' => [
            'models:App\\Models\\Order',
            'routes:GET:orders',
            'controllers:App\\Http\\Controllers\\OrderController',
            'tests:tests/Unit/JobsTest.php',
        ],
        'unmapped' => ['routes/web.php', 'App/Models/Order.php'],
    ]);
});

test('an AffectedTest serializes its file, id, distance, match, start, and the ImpactNode via', function () {
    $tests = (new AffectedTestFinder)->find(affectedTestsManifest(), ['models:App\\Models\\Order', 'tests:tests/Unit/JobsTest.php'], 1);
    $json = json_decode(json_encode($tests, JSON_THROW_ON_ERROR), true);

    expect($json)->toBe([
        ['file' => 'tests/Unit/JobsTest.php', 'id' => 'tests:tests/Unit/JobsTest.php', 'distance' => 0, 'match' => null, 'start' => 'tests:tests/Unit/JobsTest.php', 'via' => null],
        [
            'file' => 'tests/Unit/OrderTest.php',
            'id' => 'tests:tests/Unit/OrderTest.php',
            'distance' => 1,
            'match' => 'exact',
            'start' => 'models:App\\Models\\Order',
            'via' => [
                'from' => 'models:App\\Models\\Order',
                'relationship' => [
                    'from' => 'models:App\\Models\\Order',
                    'type' => 'tested_by',
                    'to' => 'tests:tests/Unit/OrderTest.php',
                    'provenance' => ['source'],
                    'resolved' => true,
                    'metadata' => ['match' => 'exact'],
                ],
                'direction' => 'out',
            ],
        ],
    ]);
});
