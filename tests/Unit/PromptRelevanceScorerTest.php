<?php

use LaravelNecromancer\Prompt\PromptRelevanceScorer;

test('scores a plain field match at default weight', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score([
        'routes' => [
            ['name' => 'orders.index', 'uri' => '/orders'],
        ],
    ], 'orders', 10);

    expect($results)->toHaveCount(1)
        ->and($results[0]['score'])->toBeGreaterThan(0);
});

test('an annotation domain match outranks a plain field match', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score([
        'routes' => [
            ['uri' => '/a', 'controller' => 'App\\Http\\Controllers\\BillingController'],
            ['uri' => '/b', 'annotations' => ['domain' => 'billing']],
        ],
    ], 'billing', 10);

    expect($results)->toHaveCount(2)
        ->and($results[0]['artifact']['uri'])->toBe('/b')
        ->and($results[0]['score'])->toBeGreaterThan($results[1]['score']);
});

test('matches annotation flow and capability at the same high weight as domain', function () {
    $scorer = new PromptRelevanceScorer;

    $flowResult = $scorer->score(['routes' => [
        ['uri' => '/a', 'annotations' => ['flow' => 'subscription-cancellation']],
    ]], 'subscription-cancellation', 10);

    $capabilityResult = $scorer->score(['routes' => [
        ['uri' => '/b', 'annotations' => ['capability' => 'subscription.cancel']],
    ]], 'subscription.cancel', 10);

    $domainResult = $scorer->score(['routes' => [
        ['uri' => '/c', 'annotations' => ['domain' => 'billing']],
    ]], 'billing', 10);

    expect($flowResult[0]['score'])->toBe($domainResult[0]['score'])
        ->and($capabilityResult[0]['score'])->toBe($domainResult[0]['score']);
});

test('matches annotation summary at mid weight like description', function () {
    $scorer = new PromptRelevanceScorer;

    $summaryResult = $scorer->score(['routes' => [
        ['uri' => '/a', 'annotations' => ['summary' => 'Cancels an active subscription.']],
    ]], 'cancels', 10);

    $descriptionResult = $scorer->score(['commands' => [
        ['signature' => 'orders:prune', 'description' => 'Cancels stale orders.'],
    ]], 'cancels', 10);

    expect($summaryResult[0]['score'])->toBe($descriptionResult[0]['score']);
});

test('still matches other annotation fields like risk and adrs at default weight', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score(['routes' => [
        ['uri' => '/a', 'annotations' => ['risk' => 'high', 'adrs' => ['docs/adr/004-cancel.md']]],
    ]], 'docs/adr/004-cancel.md', 10);

    expect($results)->toHaveCount(1)
        ->and($results[0]['score'])->toBeGreaterThan(0);
});

test('routes without annotations are unaffected', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score([
        'routes' => [
            ['name' => 'orders.index', 'uri' => '/orders'],
        ],
    ], 'orders', 10);

    expect($results)->toHaveCount(1);
});

test('reads annotations on every artifact type, not just routes', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score([
        'jobs' => [
            ['class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing']],
        ],
    ], 'billing', 10);

    expect($results)->toHaveCount(1)
        ->and($results[0]['score'])->toBeGreaterThan(0);
});

test('raw route metadata outside the necromancer namespace remains searchable at weight 1', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score(['routes' => [
        ['uri' => '/a', 'route_metadata' => ['raw' => ['head' => ['title' => 'Cancel Subscription Page']]]],
    ]], 'cancel', 10);

    expect($results)->toHaveCount(1)
        ->and($results[0]['score'])->toBeGreaterThan(0);
});

test('the route metadata necromancer namespace is not scored a second time once annotations are resolved', function () {
    $scorer = new PromptRelevanceScorer;

    $annotationsOnly = $scorer->score(['routes' => [
        ['uri' => '/a', 'annotations' => ['domain' => 'billing']],
    ]], 'billing', 10);

    $withRawNecromancerToo = $scorer->score(['routes' => [
        [
            'uri' => '/a',
            'route_metadata' => ['raw' => ['necromancer' => ['domain' => 'billing']]],
            'annotations' => ['domain' => 'billing'],
        ],
    ]], 'billing', 10);

    expect($withRawNecromancerToo[0]['score'])->toBe($annotationsOnly[0]['score']);
});
