<?php

use LaravelNecromancer\Diff\DiffReviewAgent;
use LaravelNecromancer\Diff\ManifestDiff;

/**
 * DiffReviewAgent::formatDiff() is private and only otherwise reachable through
 * review(), which requires a configured AI provider. No test in this codebase
 * exercises laravel/ai's real fake() mechanism — every AI-touching test instead
 * stubs the enclosing agent class wholesale (see NecromancerDiffCommandTest's
 * "includes AI review section" test, and NecromancerInferCommandTest's AdrInferrer
 * stubs), which would never invoke formatDiff() for real. Reflection is the only
 * way to verify this pure, private string-building method without introducing a
 * new testing convention for a single method.
 */
function formatDiffForAgent(ManifestDiff $diff): string
{
    $agent = new DiffReviewAgent;
    $method = new ReflectionMethod(DiffReviewAgent::class, 'formatDiff');

    return $method->invoke($agent, $diff);
}

test('formatDiff includes a FLAGGED ARTIFACTS section with domain flow and capability', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel',
            'annotations' => [
                'domain' => 'billing',
                'flow' => 'subscription-cancellation',
                'capability' => 'subscription.cancel',
                'risk' => 'high',
            ],
        ]]],
        removed: [],
        changed: [],
    );

    $formatted = formatDiffForAgent($diff);

    expect($formatted)->toContain('FLAGGED ARTIFACTS')
        ->and($formatted)->toContain('POST /billing/cancel (billing.cancel)')
        ->and($formatted)->toContain('domain: billing · flow: subscription-cancellation · capability: subscription.cancel · risk: high');
});

test('formatDiff omits the FLAGGED ARTIFACTS section when nothing is flagged', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']]],
        removed: [],
        changed: [],
    );

    expect(formatDiffForAgent($diff))->not->toContain('FLAGGED ARTIFACTS');
});

test('formatDiff still lists ADDED artifacts after the FLAGGED ARTIFACTS section', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel',
            'annotations' => ['risk' => 'high'],
        ]]],
        removed: [],
        changed: [],
    );

    $formatted = formatDiffForAgent($diff);

    expect(strpos($formatted, 'FLAGGED ARTIFACTS'))->toBeLessThan(strpos($formatted, 'ADDED'));
});

test('formatDiff flags a job in the FLAGGED ARTIFACTS section, not just routes', function () {
    $diff = new ManifestDiff(
        added: ['jobs' => [[
            'class' => 'App\\Jobs\\SyncStripeInvoices',
            'annotations' => ['external_services' => ['stripe']],
        ]]],
        removed: [],
        changed: [],
    );

    $formatted = formatDiffForAgent($diff);

    expect($formatted)->toContain('FLAGGED ARTIFACTS')
        ->and($formatted)->toContain('jobs')
        ->and($formatted)->toContain('SyncStripeInvoices');
});
