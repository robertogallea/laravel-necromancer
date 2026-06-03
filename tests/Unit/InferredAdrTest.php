<?php

declare(strict_types=1);

use LaravelNecromancer\Inference\InferredAdr;

test('InferredAdr exposes a counter_evidence property', function () {
    $adr = new InferredAdr(
        title: 'Test ADR',
        slug: 'test-adr',
        status: 'accepted',
        context: 'Some context.',
        decision: 'Some decision.',
        consequences: 'Some consequences.',
        counter_evidence: 'No contradicting evidence.',
    );

    expect($adr->counter_evidence)->toBe('No contradicting evidence.');
});

test('counter_evidence defaults to empty string when omitted', function () {
    $adr = new InferredAdr(
        title: 'Test', slug: 'test', status: 'accepted',
        context: 'ctx', decision: 'dec', consequences: 'con',
    );

    expect($adr->counter_evidence)->toBe('');
});
