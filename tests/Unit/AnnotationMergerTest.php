<?php

use LaravelNecromancer\Metadata\AnnotationMerger;
use LaravelNecromancer\Metadata\ArtifactAnnotations;
use LaravelNecromancer\Metadata\Risk;
use LaravelNecromancer\Tests\TestCase;

uses(TestCase::class)->group('class-annotations');

test('a more specific scalar refines an absent base value silently', function () {
    $base = new ArtifactAnnotations(domain: 'billing');
    $specific = new ArtifactAnnotations(capability: 'subscription.cancel');

    [$result, $diagnostics] = (new AnnotationMerger)->merge($base, $specific);

    expect($result->domain)->toBe('billing')
        ->and($result->capability)->toBe('subscription.cancel')
        ->and($diagnostics)->toBe([]);
});

test('a more specific scalar silently overrides a different base value when warnings are disabled', function () {
    $base = new ArtifactAnnotations(domain: 'billing');
    $specific = new ArtifactAnnotations(domain: 'support');

    [$result, $diagnostics] = (new AnnotationMerger)->merge($base, $specific);

    expect($result->domain)->toBe('support')
        ->and($diagnostics)->toBe([]);
});

test('a more specific scalar overrides a different base value with a warning naming the artifact, field, and both values', function () {
    $base = new ArtifactAnnotations(domain: 'billing', risk: Risk::Low);
    $specific = new ArtifactAnnotations(domain: 'support');

    [$result, $diagnostics] = (new AnnotationMerger)->merge(
        $base,
        $specific,
        warnOnConflict: true,
        artifactLabel: 'GET /billing/cancel',
        baseSourceLabel: 'controller',
        moreSpecificSourceLabel: 'route metadata',
    );

    expect($result->domain)->toBe('support')
        ->and($result->risk)->toBe(Risk::Low)
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0])->toContain('AN_SOURCE_CONFLICT')
        ->and($diagnostics[0])->toContain('GET /billing/cancel')
        ->and($diagnostics[0])->toContain('domain')
        ->and($diagnostics[0])->toContain('route metadata')
        ->and($diagnostics[0])->toContain('controller')
        ->and($diagnostics[0])->toContain('support')
        ->and($diagnostics[0])->toContain('billing');
});

test('conflict diagnostics for different artifacts stay distinguishable after array_unique', function () {
    $base = new ArtifactAnnotations(domain: 'billing');
    $specific = new ArtifactAnnotations(domain: 'support');

    [, $first] = (new AnnotationMerger)->merge($base, $specific, warnOnConflict: true, artifactLabel: 'GET /a');
    [, $second] = (new AnnotationMerger)->merge($base, $specific, warnOnConflict: true, artifactLabel: 'GET /b');

    expect(array_unique([...$first, ...$second]))->toHaveCount(2);
});

test('equal scalar values never produce a conflict diagnostic', function () {
    $base = new ArtifactAnnotations(domain: 'billing');
    $specific = new ArtifactAnnotations(domain: 'billing');

    [$result, $diagnostics] = (new AnnotationMerger)->merge($base, $specific, warnOnConflict: true);

    expect($result->domain)->toBe('billing')
        ->and($diagnostics)->toBe([]);
});

test('risk conflicts are diagnosed the same way as string scalars', function () {
    $base = new ArtifactAnnotations(risk: Risk::Low);
    $specific = new ArtifactAnnotations(risk: Risk::High);

    [$result, $diagnostics] = (new AnnotationMerger)->merge($base, $specific, warnOnConflict: true);

    expect($result->risk)->toBe(Risk::High)
        ->and($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0])->toContain('risk');
});

test('list fields merge additively from least to most specific with exact dedupe', function () {
    $base = new ArtifactAnnotations(externalServices: ['stripe'], adrs: ['docs/adr/001.md']);
    $specific = new ArtifactAnnotations(externalServices: ['stripe', 'sendgrid'], adrs: ['docs/adr/002.md']);

    [$result, $diagnostics] = (new AnnotationMerger)->merge($base, $specific, warnOnConflict: true);

    expect($result->externalServices)->toBe(['stripe', 'sendgrid'])
        ->and($result->adrs)->toBe(['docs/adr/001.md', 'docs/adr/002.md'])
        ->and($diagnostics)->toBe([]);
});
