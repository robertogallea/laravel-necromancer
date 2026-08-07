<?php

use LaravelNecromancer\Collection\RouteMetadataNormalizer;

test('normalize returns empty array when namespace key is absent', function () {
    $normalizer = new RouteMetadataNormalizer;

    expect($normalizer->normalize(['head' => ['title' => 'Users']]))->toBe([]);
});

test('normalize returns empty array when namespace value is not an array', function () {
    $normalizer = new RouteMetadataNormalizer;

    expect($normalizer->normalize(['necromancer' => 'billing']))->toBe([]);
});

test('normalize extracts all supported fields under the necromancer namespace', function () {
    $normalizer = new RouteMetadataNormalizer;

    $result = $normalizer->normalize([
        'necromancer' => [
            'domain' => 'billing',
            'flow' => 'subscription-cancellation',
            'capability' => 'subscription.cancel',
            'summary' => 'Cancels an active subscription.',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adr' => 'docs/adr/004-subscription-cancellation.md',
        ],
    ]);

    expect($result)->toBe([
        'domain' => 'billing',
        'flow' => 'subscription-cancellation',
        'capability' => 'subscription.cancel',
        'summary' => 'Cancels an active subscription.',
        'risk' => 'high',
        'external_services' => ['stripe'],
        'adr' => 'docs/adr/004-subscription-cancellation.md',
    ]);
});

test('normalize respects a custom namespace', function () {
    $normalizer = new RouteMetadataNormalizer('acme');

    $result = $normalizer->normalize([
        'necromancer' => ['domain' => 'ignored-because-wrong-namespace'],
        'acme' => ['domain' => 'billing'],
    ]);

    expect($result)->toBe(['domain' => 'billing']);
});

test('normalize coerces a scalar external_services value into a list', function () {
    $normalizer = new RouteMetadataNormalizer;

    $result = $normalizer->normalize(['necromancer' => ['external_services' => 'stripe']]);

    expect($result)->toBe(['external_services' => ['stripe']]);
});

test('normalize retains plural ADR declarations', function () {
    $normalizer = new RouteMetadataNormalizer;

    expect($normalizer->normalize(['necromancer' => [
        'adr' => 'docs/adr/001.md',
        'adrs' => ['docs/adr/002.md'],
    ]]))->toBe([
        'adr' => 'docs/adr/001.md',
        'adrs' => ['docs/adr/002.md'],
    ]);
});

test('normalize trims legacy scalar values before creating the compatibility projection', function () {
    $normalizer = new RouteMetadataNormalizer;

    expect($normalizer->normalize(['necromancer' => [
        'domain' => ' billing ',
        'risk' => ' high ',
        'external_services' => [' stripe '],
    ]]))->toBe([
        'domain' => 'billing',
        'risk' => 'high',
        'external_services' => ['stripe'],
    ]);
});

test('normalize drops non-scalar values instead of throwing', function () {
    $normalizer = new RouteMetadataNormalizer;

    $result = $normalizer->normalize([
        'necromancer' => [
            'domain' => ['not', 'a', 'string'],
            'risk' => 'critical',
        ],
    ]);

    expect($result)->toBe(['risk' => 'critical']);
});

test('normalize returns an empty array when the necromancer namespace has no supported fields', function () {
    $normalizer = new RouteMetadataNormalizer;

    expect($normalizer->normalize(['necromancer' => ['unsupported' => 'value']]))->toBe([]);
});
