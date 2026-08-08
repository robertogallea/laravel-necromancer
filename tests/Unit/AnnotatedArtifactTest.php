<?php

use LaravelNecromancer\Metadata\AnnotatedArtifact;

test('collect() ignores artifacts without an annotations key', function () {
    $collected = AnnotatedArtifact::collect([
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders'],
        ],
    ]);

    expect($collected)->toBeEmpty();
});

test('collect() ignores artifacts with an empty annotations array', function () {
    $collected = AnnotatedArtifact::collect([
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'annotations' => []],
        ],
    ]);

    expect($collected)->toBeEmpty();
});

test('collect() builds a route label from method and uri, and uses the controller as subject', function () {
    $collected = AnnotatedArtifact::collect([
        'routes' => [
            [
                'id' => 'routes:POST:billing/cancel',
                'method' => 'POST',
                'uri' => 'billing/cancel',
                'controller' => 'App\\Http\\Controllers\\SubscriptionController',
                'annotations' => ['domain' => 'billing'],
            ],
        ],
    ]);

    expect($collected)->toHaveCount(1)
        ->and($collected[0]->type)->toBe('routes')
        ->and($collected[0]->label)->toBe('POST billing/cancel')
        ->and($collected[0]->subject)->toBe('App\\Http\\Controllers\\SubscriptionController')
        ->and($collected[0]->annotations)->toBe(['domain' => 'billing']);
});

test('collect() uses the class field as label and subject for class-backed artifacts', function () {
    $collected = AnnotatedArtifact::collect([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['risk' => 'high']],
        ],
    ]);

    expect($collected[0]->label)->toBe('App\\Jobs\\SendInvoice')
        ->and($collected[0]->subject)->toBe('App\\Jobs\\SendInvoice');
});

test('collect() uses the declared subject field, not class, for test artifacts', function () {
    $collected = AnnotatedArtifact::collect([
        'tests' => [
            [
                'id' => 'tests:tests/Feature/BillingTest.php',
                'file' => 'tests/Feature/BillingTest.php',
                'class' => 'Tests\\Feature\\BillingTest',
                'subject' => 'App\\Models\\Subscription',
                'annotations' => ['domain' => 'billing'],
            ],
        ],
    ]);

    expect($collected[0]->label)->toBe('tests/Feature/BillingTest.php')
        ->and($collected[0]->subject)->toBe('App\\Models\\Subscription');
});

test('collect() has no matchable subject for gates and scheduled tasks', function () {
    $collected = AnnotatedArtifact::collect([
        'gates' => [
            ['id' => 'gates:ability:edit-post', 'ability' => 'edit-post', 'kind' => 'closure', 'annotations' => ['domain' => 'content']],
        ],
        'scheduled_tasks' => [
            ['id' => 'scheduled_tasks:abc123:1', 'command' => 'orders:prune', 'annotations' => ['domain' => 'ops']],
        ],
    ]);

    expect($collected)->toHaveCount(2)
        ->and($collected[0]->label)->toBe('gate:edit-post')
        ->and($collected[0]->subject)->toBeNull()
        ->and($collected[1]->label)->toBe('scheduled task: orders:prune')
        ->and($collected[1]->subject)->toBeNull();
});

test('collect() builds source as file:line when present', function () {
    $collected = AnnotatedArtifact::collect([
        'models' => [
            [
                'id' => 'models:App\\Models\\Order',
                'class' => 'App\\Models\\Order',
                'annotations' => ['domain' => 'billing'],
                'source' => ['file' => 'app/Models/Order.php', 'line' => 12],
            ],
        ],
    ]);

    expect($collected[0]->source)->toBe('app/Models/Order.php:12');
});
