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

test('formatDiff includes a FLAGGED ROUTES section with domain flow and capability', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel',
            'route_metadata' => ['necromancer' => [
                'domain' => 'billing',
                'flow' => 'subscription-cancellation',
                'capability' => 'subscription.cancel',
                'risk' => 'high',
            ]],
        ]]],
        removed: [],
        changed: [],
    );

    $formatted = formatDiffForAgent($diff);

    expect($formatted)->toContain('FLAGGED ROUTES')
        ->and($formatted)->toContain('POST /billing/cancel (billing.cancel)')
        ->and($formatted)->toContain('domain: billing · flow: subscription-cancellation · capability: subscription.cancel · risk: high');
});

test('formatDiff omits the FLAGGED ROUTES section when no route is flagged', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [['method' => 'GET', 'uri' => '/orders', 'name' => 'orders.index']]],
        removed: [],
        changed: [],
    );

    expect(formatDiffForAgent($diff))->not->toContain('FLAGGED ROUTES');
});

test('formatDiff still lists ADDED artifacts after the FLAGGED ROUTES section', function () {
    $diff = new ManifestDiff(
        added: ['routes' => [[
            'method' => 'POST', 'uri' => '/billing/cancel', 'name' => 'billing.cancel',
            'route_metadata' => ['necromancer' => ['risk' => 'high']],
        ]]],
        removed: [],
        changed: [],
    );

    $formatted = formatDiffForAgent($diff);

    expect(strpos($formatted, 'FLAGGED ROUTES'))->toBeLessThan(strpos($formatted, 'ADDED'));
});
