<?php

use LaravelNecromancer\Okf\ConceptEnrichment;
use LaravelNecromancer\Okf\ConceptLink;
use LaravelNecromancer\Okf\GroupConceptBuilder;

function sampleGroupEnrichment(): ConceptEnrichment
{
    return new ConceptEnrichment(
        description: 'Everything belonging to the billing domain.',
        narrative: 'These artifacts together implement billing.',
        provider: 'anthropic',
        model: 'claude-sonnet-4-6',
        promptVersion: '1',
        privacyPolicy: 'excludes-source-framework-config-adr-bodies',
        cacheKey: 'sha256:def456',
        cached: true,
    );
}

test('identify() derives id, title, and filename for a domain value', function () {
    $builder = new GroupConceptBuilder;

    $identity = $builder->identify('domain', 'billing');

    expect($identity['id'])->toBe('domain:billing')
        ->and($identity['title'])->toBe('billing')
        ->and($identity['filename'])->toBe('billing-'.substr(hash('sha256', 'domain:billing'), 0, 8).'.md');
});

test('build() sets front matter type to the concept kind and lists member ids under necromancer.members', function () {
    $members = [
        'jobs:App\\Jobs\\SendInvoice' => new ConceptLink('App\\Jobs\\SendInvoice', '/artifacts/send-invoice.md'),
        'routes:GET:orders' => new ConceptLink('GET orders', '/artifacts/get-orders.md'),
    ];

    $concept = (new GroupConceptBuilder)->build('domain', 'billing', $members, '2026-08-07T12:00:00+02:00');

    expect($concept->id)->toBe('domain:billing')
        ->and($concept->content)->toContain('title: "billing"')
        ->and($concept->content)->toContain('type: "domain"')
        ->and($concept->content)->toContain('concept_type: "domain"')
        ->and($concept->content)->toContain('generated_at: "2026-08-07T12:00:00+02:00"')
        ->and($concept->content)->toContain('members:')
        ->and($concept->content)->toContain('"jobs:App\\\\Jobs\\\\SendInvoice"')
        ->and($concept->content)->toContain('"routes:GET:orders"');
});

test('build() renders each member as a link in the Artifacts body section', function () {
    $members = ['jobs:App\\Jobs\\SendInvoice' => new ConceptLink('App\\Jobs\\SendInvoice', '/artifacts/send-invoice.md')];

    $concept = (new GroupConceptBuilder)->build('flow', 'subscription-cancellation', $members, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('# subscription-cancellation')
        ->and($concept->content)->toContain('_flow concept_')
        ->and($concept->content)->toContain('## Artifacts')
        ->and($concept->content)->toContain('- [App\\Jobs\\SendInvoice](/artifacts/send-invoice.md)');
});

test('build() renders a fallback line when there are no members', function () {
    $concept = (new GroupConceptBuilder)->build('domain', 'orphan', [], '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('_No member artifacts._');
});

test('build() omits enrichment front matter and body section when no enrichment is passed', function () {
    $concept = (new GroupConceptBuilder)->build('domain', 'billing', [], '2026-08-07T12:00:00+02:00');

    expect($concept->content)->not->toContain('enrichment:')
        ->and($concept->content)->not->toContain('## AI-Enriched Summary');
});

test('build() records enrichment provenance and narrative when enriched, without touching members or id', function () {
    $members = ['jobs:App\\Jobs\\SendInvoice' => new ConceptLink('App\\Jobs\\SendInvoice', '/artifacts/send-invoice.md')];

    $plain = (new GroupConceptBuilder)->build('domain', 'billing', $members, '2026-08-07T12:00:00+02:00');
    $enriched = (new GroupConceptBuilder)->build('domain', 'billing', $members, '2026-08-07T12:00:00+02:00', sampleGroupEnrichment());

    expect($enriched->id)->toBe($plain->id)
        ->and($enriched->filename)->toBe($plain->filename)
        ->and($enriched->content)->toContain('"jobs:App\\\\Jobs\\\\SendInvoice"')
        ->and($enriched->content)->toContain('description: "Everything belonging to the billing domain."')
        ->and($enriched->content)->toContain('cache_key: "sha256:def456"')
        ->and($enriched->content)->toContain('cached: true')
        ->and($enriched->content)->toContain('## AI-Enriched Summary')
        ->and($enriched->content)->toContain('These artifacts together implement billing.');
});

test('build() is deterministic regardless of member insertion order', function () {
    $membersA = [
        'a:1' => new ConceptLink('A', '/artifacts/a.md'),
        'b:2' => new ConceptLink('B', '/artifacts/b.md'),
    ];
    $membersB = [
        'b:2' => new ConceptLink('B', '/artifacts/b.md'),
        'a:1' => new ConceptLink('A', '/artifacts/a.md'),
    ];

    $conceptA = (new GroupConceptBuilder)->build('domain', 'billing', $membersA, '2026-08-07T12:00:00+02:00');
    $conceptB = (new GroupConceptBuilder)->build('domain', 'billing', $membersB, '2026-08-07T12:00:00+02:00');

    expect($conceptA->content)->toBe($conceptB->content);
});
