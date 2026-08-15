<?php

use LaravelNecromancer\Doctor\DimensionResult;
use LaravelNecromancer\Doctor\DoctorAnalyzer;

function artifactAnnotationDimension(array $artifacts): DimensionResult
{
    $analyzer = new DoctorAnalyzer($artifacts);

    foreach ($analyzer->dimensions() as $dimension) {
        if ($dimension->key === 'artifact-annotation-coverage') {
            return $dimension;
        }
    }

    throw new RuntimeException('artifact-annotation-coverage dimension not found');
}

test('dimensions() includes artifact-annotation-coverage as an 8th dimension', function () {
    $analyzer = new DoctorAnalyzer(['routes' => []]);

    expect($analyzer->dimensions())->toHaveCount(8);
});

test('artifact-annotation-coverage label is Artifact Annotation Coverage', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing']],
        ],
    ]);

    expect($dimension->label)->toBe('Artifact Annotation Coverage');
});

test('artifact-annotation-coverage scores N/A when no artifact declares annotations', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/orders'],
            ['method' => 'POST', 'uri' => '/orders', 'route_metadata' => ['raw' => ['head' => ['title' => 'x']]]],
        ],
    ]);

    expect($dimension->score)->toBe(1.0)
        ->and($dimension->detail)->toBe('N/A');
});

test('artifact-annotation-coverage scores domain coverage among annotated routes', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing']],
            ['method' => 'GET', 'uri' => '/b', 'annotations' => ['risk' => 'low']],
        ],
    ]);

    expect($dimension->score)->toBe(0.5)
        ->and($dimension->detail)->toContain('1/2 tagged with domain');
});

test('artifact-annotation-coverage penalizes high-risk routes missing an ADR reference', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing', 'risk' => 'high', 'adrs' => ['docs/adr/1.md']]],
            ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'billing', 'risk' => 'critical']],
        ],
    ]);

    expect($dimension->detail)->toContain('1/2 high-risk with ADR');
});

test('artifact-annotation-coverage penalizes external-service routes without matching test evidence', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            [
                'method' => 'GET', 'uri' => '/a', 'controller' => 'App\\Http\\Controllers\\StripeController',
                'annotations' => ['domain' => 'billing', 'external_services' => ['stripe']],
            ],
            [
                'method' => 'GET', 'uri' => '/b', 'controller' => 'App\\Http\\Controllers\\SlackController',
                'annotations' => ['domain' => 'billing', 'external_services' => ['slack']],
            ],
        ],
        'tests' => [
            ['subject' => 'App\\Http\\Controllers\\StripeController'],
        ],
    ]);

    expect($dimension->detail)->toContain('1/2 external-service artifacts tested');
});

test('artifact-annotation-coverage scores full flow consistency when a flow group agrees on domain and risk', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']],
            ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']],
        ],
    ]);

    expect($dimension->detail)->toContain('2/2 flow-consistent');
});

test('artifact-annotation-coverage penalizes a flow group with conflicting risk levels', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']],
            ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'low']],
        ],
    ]);

    expect($dimension->detail)->toContain('0/2 flow-consistent');
});

test('artifact-annotation-coverage does not mention flow consistency when no flow has multiple routes', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing', 'flow' => 'cancel']],
            ['method' => 'GET', 'uri' => '/b', 'annotations' => ['domain' => 'billing', 'flow' => 'refund']],
        ],
    ]);

    expect($dimension->detail)->not->toContain('flow-consistent');
});

test('artifact-annotation-coverage weight is 0.10', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'GET', 'uri' => '/a', 'annotations' => ['domain' => 'billing']],
        ],
    ]);

    expect($dimension->weight)->toBe(0.10);
});

test('artifact-annotation-coverage scores annotations declared on non-route artifacts', function () {
    $dimension = artifactAnnotationDimension([
        'jobs' => [
            ['class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing']],
            ['class' => 'App\\Jobs\\ProcessRefund'],
        ],
    ]);

    expect($dimension->score)->toBe(1.0)
        ->and($dimension->detail)->toContain('1/1 tagged with domain');
});

test('artifact-annotation-coverage flags a high-risk job with no matching test as untested for external services', function () {
    $dimension = artifactAnnotationDimension([
        'jobs' => [
            ['class' => 'App\\Jobs\\SyncStripeInvoices', 'annotations' => ['domain' => 'billing', 'external_services' => ['stripe']]],
        ],
        'tests' => [],
    ]);

    expect($dimension->detail)->toContain('0/1 external-service artifacts tested');
});

test('artifact-annotation-coverage groups flow consistency across artifact types', function () {
    $dimension = artifactAnnotationDimension([
        'routes' => [
            ['method' => 'POST', 'uri' => '/billing/cancel', 'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high']],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\RevokeAccess', 'annotations' => ['domain' => 'access', 'flow' => 'cancel', 'risk' => 'high']],
        ],
    ]);

    expect($dimension->detail)->toContain('0/2 flow-consistent');
});

test('a route-only annotated application scores the same as before universal annotations', function () {
    $artifacts = [
        'routes' => [
            [
                'method' => 'GET', 'uri' => '/a', 'controller' => 'App\\Http\\Controllers\\StripeController',
                'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high', 'external_services' => ['stripe'], 'adrs' => ['docs/adr/1.md']],
            ],
            [
                'method' => 'GET', 'uri' => '/b',
                'annotations' => ['domain' => 'billing', 'flow' => 'cancel', 'risk' => 'high', 'adrs' => ['docs/adr/1.md']],
            ],
        ],
        'tests' => [
            ['subject' => 'App\\Http\\Controllers\\StripeController'],
        ],
    ];

    $dimension = artifactAnnotationDimension($artifacts);

    expect($dimension->score)->toBe(1.0);
});
