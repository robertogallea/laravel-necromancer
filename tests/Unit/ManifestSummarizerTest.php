<?php

declare(strict_types=1);

use LaravelNecromancer\Inference\ManifestSummarizer;

function makeManifest(array $artifacts = [], array $meta = []): array
{
    return [
        'meta' => array_merge(['app_name' => 'TestApp'], $meta),
        'artifacts' => $artifacts,
    ];
}

test('summarize returns an app name header', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest());

    expect($result)->toContain('App: TestApp');
});

test('summarize includes routes section when routes are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest([
        'routes' => [
            ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => ['auth']],
        ],
    ]));

    expect($result)->toContain('Routes (1)');
    expect($result)->toContain('orders.index');
    expect($result)->toContain('GET /orders');
    expect($result)->toContain('[auth]');
});

test('summarize omits routes section when no routes are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest());

    expect($result)->not->toContain('Routes');
});

test('summarize includes models section when models are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest([
        'models' => [
            [
                'class' => 'App\\Models\\Order',
                'table' => 'orders',
                'relationships' => [
                    ['type' => 'belongsTo', 'related' => 'App\\Models\\Customer', 'method' => 'customer'],
                ],
            ],
        ],
    ]));

    expect($result)->toContain('Models (1)');
    expect($result)->toContain('Order');
    expect($result)->toContain('table=orders');
    expect($result)->toContain('belongsTo Customer');
});

test('summarize includes jobs section when jobs are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest([
        'jobs' => [
            ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'tries' => 3],
        ],
    ]));

    expect($result)->toContain('Jobs (1)');
    expect($result)->toContain('SendInvoiceEmail');
    expect($result)->toContain('queue=emails');
    expect($result)->toContain('tries=3');
});

test('summarize includes events section when events are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest([
        'events' => [
            ['class' => 'App\\Events\\OrderPlaced', 'listeners' => ['App\\Listeners\\SendReceipt']],
        ],
    ]));

    expect($result)->toContain('Events (1)');
    expect($result)->toContain('OrderPlaced');
    expect($result)->toContain('SendReceipt');
});

test('summarize includes policies section when policies are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest([
        'policies' => [
            ['class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order'],
        ],
    ]));

    expect($result)->toContain('Policies (1)');
    expect($result)->toContain('Order→OrderPolicy');
});

test('summarize includes commands section when commands are present', function () {
    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest([
        'commands' => [
            ['class' => 'App\\Console\\Commands\\PruneOrders', 'signature' => 'orders:prune {--days=30}', 'description' => 'Prune orders'],
        ],
    ]));

    expect($result)->toContain('Commands (1)');
    expect($result)->toContain('orders:prune {--days=30}');
});

test('summarize caps routes at 30 entries', function () {
    $routes = array_map(
        fn (int $i) => ['name' => "route.{$i}", 'method' => 'GET', 'uri' => "/route/{$i}", 'middleware' => []],
        range(1, 35),
    );

    $summarizer = new ManifestSummarizer;
    $result = $summarizer->summarize(makeManifest(['routes' => $routes]));

    expect($result)->toContain('Routes (35)');
    expect($result)->toContain('(5 more not shown)');
});
