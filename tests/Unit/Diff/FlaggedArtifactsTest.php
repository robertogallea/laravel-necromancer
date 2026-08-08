<?php

use LaravelNecromancer\Diff\FlaggedArtifacts;
use LaravelNecromancer\Diff\ManifestDiff;

test('isFlagged is true for a high or critical risk artifact', function () {
    expect(FlaggedArtifacts::isFlagged(['annotations' => ['risk' => 'high']]))->toBeTrue()
        ->and(FlaggedArtifacts::isFlagged(['annotations' => ['risk' => 'critical']]))->toBeTrue()
        ->and(FlaggedArtifacts::isFlagged(['annotations' => ['risk' => 'low']]))->toBeFalse();
});

test('isFlagged is true for an artifact declaring external services', function () {
    expect(FlaggedArtifacts::isFlagged(['annotations' => ['external_services' => ['stripe']]]))->toBeTrue();
});

test('isFlagged is false for an artifact with no annotations', function () {
    expect(FlaggedArtifacts::isFlagged(['method' => 'GET', 'uri' => '/orders']))->toBeFalse();
});

test('isFlagged is true for a high-risk job, not just routes', function () {
    expect(FlaggedArtifacts::isFlagged(['class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['risk' => 'critical']]))->toBeTrue();
});

test('reason lists domain flow capability risk and external services in order', function () {
    $reason = FlaggedArtifacts::reason([
        'annotations' => [
            'domain' => 'billing',
            'flow' => 'subscription-cancellation',
            'capability' => 'subscription.cancel',
            'risk' => 'high',
            'external_services' => ['stripe'],
        ],
    ]);

    expect($reason)->toBe('domain: billing · flow: subscription-cancellation · capability: subscription.cancel · risk: high · external services: stripe');
});

test('reason omits fields that are not declared', function () {
    $reason = FlaggedArtifacts::reason(['annotations' => ['risk' => 'high']]);

    expect($reason)->toBe('risk: high');
});

test('fromDiff returns flagged artifacts from added and changed, ignores removed', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [
            ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['risk' => 'high']],
            ['method' => 'GET', 'uri' => '/orders'],
        ]],
        removed: ['routes' => [
            ['method' => 'GET', 'uri' => '/legacy', 'annotations' => ['risk' => 'high']],
        ]],
        changed: ['routes' => [
            ['from' => ['method' => 'GET', 'uri' => '/stripe/webhook'], 'to' => ['method' => 'GET', 'uri' => '/stripe/webhook', 'annotations' => ['external_services' => ['stripe']]]],
        ]],
    );

    $flagged = FlaggedArtifacts::fromDiff($diff);

    expect($flagged)->toHaveCount(2)
        ->and($flagged[0]['type'])->toBe('routes')
        ->and($flagged[0]['artifact']['uri'])->toBe('/billing/cancel')
        ->and($flagged[1]['artifact']['uri'])->toBe('/stripe/webhook');
});

test('fromDiff flags artifacts across multiple types, not just routes', function () {
    $diff = new ManifestDiff(
        added: [
            'routes' => [
                ['method' => 'GET', 'uri' => '/orders'],
            ],
            'jobs' => [
                ['class' => 'App\\Jobs\\SyncStripeInvoices', 'annotations' => ['external_services' => ['stripe']]],
            ],
        ],
        removed: [],
        changed: [
            'models' => [
                ['from' => ['class' => 'App\\Models\\Order'], 'to' => ['class' => 'App\\Models\\Order', 'annotations' => ['risk' => 'critical']]],
            ],
        ],
    );

    $flagged = FlaggedArtifacts::fromDiff($diff);

    $types = array_column($flagged, 'type');

    expect($flagged)->toHaveCount(2)
        ->and($types)->toContain('jobs')
        ->and($types)->toContain('models');
});
