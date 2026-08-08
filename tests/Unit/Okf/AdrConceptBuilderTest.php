<?php

use LaravelNecromancer\Okf\AdrConceptBuilder;
use LaravelNecromancer\Okf\ConceptEnrichment;
use LaravelNecromancer\Okf\ConceptLink;

function sampleAdrEnrichment(): ConceptEnrichment
{
    return new ConceptEnrichment(
        description: 'Explains why subscription cancellation moved to a listener.',
        narrative: 'The team chose to decouple cancellation from the controller.',
        provider: 'anthropic',
        model: 'claude-sonnet-4-6',
        promptVersion: '1',
        privacyPolicy: 'excludes-source-framework-config-adr-bodies',
        cacheKey: 'sha256:ghi789',
        cached: false,
    );
}

test('identify() derives id, title, and filename from the ADR path, without reading the file', function () {
    $builder = new AdrConceptBuilder;

    $identity = $builder->identify('docs/adr/0004-subscription-cancellation.md');

    expect($identity['id'])->toBe('adr:docs/adr/0004-subscription-cancellation.md')
        ->and($identity['title'])->toBe('0004-subscription-cancellation')
        ->and($identity['filename'])->toBe(
            '0004-subscription-cancellation-'.substr(hash('sha256', 'adr:docs/adr/0004-subscription-cancellation.md'), 0, 8).'.md'
        );
});

test('build() records the source path and referencing artifact ids under the necromancer namespace', function () {
    $referencedBy = [
        'jobs:App\\Jobs\\SendInvoice' => new ConceptLink('App\\Jobs\\SendInvoice', '/artifacts/send-invoice.md'),
        'routes:POST:billing/cancel' => new ConceptLink('POST billing/cancel', '/artifacts/billing-cancel.md'),
    ];

    $concept = (new AdrConceptBuilder)->build(
        'docs/adr/0004-x.md',
        "# ADR 0004\n\nDecision text.",
        $referencedBy,
        '2026-08-07T12:00:00+02:00',
    );

    expect($concept->id)->toBe('adr:docs/adr/0004-x.md')
        ->and($concept->content)->toContain('type: "adr"')
        ->and($concept->content)->toContain('concept_type: "adr"')
        ->and($concept->content)->toContain('generated_at: "2026-08-07T12:00:00+02:00"')
        ->and($concept->content)->toContain('source:')
        ->and($concept->content)->toContain('file: "docs/adr/0004-x.md"')
        ->and($concept->content)->toContain('referenced_by:')
        ->and($concept->content)->toContain('"jobs:App\\\\Jobs\\\\SendInvoice"')
        ->and($concept->content)->toContain('"routes:POST:billing/cancel"');
});

test('build() lists referencing artifacts as links and mirrors the original ADR content', function () {
    $referencedBy = ['jobs:App\\Jobs\\SendInvoice' => new ConceptLink('App\\Jobs\\SendInvoice', '/artifacts/send-invoice.md')];

    $concept = (new AdrConceptBuilder)->build(
        'docs/adr/0004-x.md',
        "# ADR 0004\n\nDecision text.",
        $referencedBy,
        '2026-08-07T12:00:00+02:00',
    );

    expect($concept->content)->toContain('## Referenced By')
        ->and($concept->content)->toContain('- [App\\Jobs\\SendInvoice](/artifacts/send-invoice.md)')
        ->and($concept->content)->toContain("# ADR 0004\n\nDecision text.");
});

test('build() renders a fallback line when nothing references the ADR', function () {
    $concept = (new AdrConceptBuilder)->build('docs/adr/0004-x.md', 'Body.', [], '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('_No referencing artifacts._');
});

test('build() omits enrichment front matter and body section when no enrichment is passed', function () {
    $concept = (new AdrConceptBuilder)->build('docs/adr/0004-x.md', 'Body.', [], '2026-08-07T12:00:00+02:00');

    expect($concept->content)->not->toContain('enrichment:')
        ->and($concept->content)->not->toContain('## AI-Enriched Summary');
});

test('build() records enrichment provenance and narrative without altering source or copied content', function () {
    $plain = (new AdrConceptBuilder)->build('docs/adr/0004-x.md', "# ADR 0004\n\nDecision text.", [], '2026-08-07T12:00:00+02:00');
    $enriched = (new AdrConceptBuilder)->build('docs/adr/0004-x.md', "# ADR 0004\n\nDecision text.", [], '2026-08-07T12:00:00+02:00', sampleAdrEnrichment());

    expect($enriched->id)->toBe($plain->id)
        ->and($enriched->filename)->toBe($plain->filename)
        ->and($enriched->content)->toContain('file: "docs/adr/0004-x.md"')
        ->and($enriched->content)->toContain("# ADR 0004\n\nDecision text.")
        ->and($enriched->content)->toContain('description: "Explains why subscription cancellation moved to a listener."')
        ->and($enriched->content)->toContain('cache_key: "sha256:ghi789"')
        ->and($enriched->content)->toContain('## AI-Enriched Summary')
        ->and($enriched->content)->toContain('The team chose to decouple cancellation from the controller.');
});

test('build() is deterministic regardless of referencedBy insertion order', function () {
    $a = ['a:1' => new ConceptLink('A', '/artifacts/a.md'), 'b:2' => new ConceptLink('B', '/artifacts/b.md')];
    $b = ['b:2' => new ConceptLink('B', '/artifacts/b.md'), 'a:1' => new ConceptLink('A', '/artifacts/a.md')];

    $conceptA = (new AdrConceptBuilder)->build('docs/adr/x.md', 'Body.', $a, '2026-08-07T12:00:00+02:00');
    $conceptB = (new AdrConceptBuilder)->build('docs/adr/x.md', 'Body.', $b, '2026-08-07T12:00:00+02:00');

    expect($conceptA->content)->toBe($conceptB->content);
});
