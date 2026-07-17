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

test('a route metadata domain match outranks a plain field match', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score([
        'routes' => [
            ['uri' => '/a', 'controller' => 'App\\Http\\Controllers\\BillingController'],
            ['uri' => '/b', 'route_metadata' => ['necromancer' => ['domain' => 'billing']]],
        ],
    ], 'billing', 10);

    expect($results)->toHaveCount(2)
        ->and($results[0]['artifact']['uri'])->toBe('/b')
        ->and($results[0]['score'])->toBeGreaterThan($results[1]['score']);
});

test('matches route metadata flow and capability at the same high weight as domain', function () {
    $scorer = new PromptRelevanceScorer;

    $flowResult = $scorer->score(['routes' => [
        ['uri' => '/a', 'route_metadata' => ['necromancer' => ['flow' => 'subscription-cancellation']]],
    ]], 'subscription-cancellation', 10);

    $capabilityResult = $scorer->score(['routes' => [
        ['uri' => '/b', 'route_metadata' => ['necromancer' => ['capability' => 'subscription.cancel']]],
    ]], 'subscription.cancel', 10);

    $domainResult = $scorer->score(['routes' => [
        ['uri' => '/c', 'route_metadata' => ['necromancer' => ['domain' => 'billing']]],
    ]], 'billing', 10);

    expect($flowResult[0]['score'])->toBe($domainResult[0]['score'])
        ->and($capabilityResult[0]['score'])->toBe($domainResult[0]['score']);
});

test('matches route metadata summary at mid weight like description', function () {
    $scorer = new PromptRelevanceScorer;

    $summaryResult = $scorer->score(['routes' => [
        ['uri' => '/a', 'route_metadata' => ['necromancer' => ['summary' => 'Cancels an active subscription.']]],
    ]], 'cancels', 10);

    $descriptionResult = $scorer->score(['commands' => [
        ['signature' => 'orders:prune', 'description' => 'Cancels stale orders.'],
    ]], 'cancels', 10);

    expect($summaryResult[0]['score'])->toBe($descriptionResult[0]['score']);
});

test('still matches other route metadata fields like risk and adr at default weight', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score(['routes' => [
        ['uri' => '/a', 'route_metadata' => ['necromancer' => ['risk' => 'high', 'adr' => 'docs/adr/004-cancel.md']]],
    ]], 'docs/adr/004-cancel.md', 10);

    expect($results)->toHaveCount(1)
        ->and($results[0]['score'])->toBeGreaterThan(0);
});

test('routes without route metadata are unaffected', function () {
    $scorer = new PromptRelevanceScorer;

    $results = $scorer->score([
        'routes' => [
            ['name' => 'orders.index', 'uri' => '/orders'],
        ],
    ], 'orders', 10);

    expect($results)->toHaveCount(1);
});
