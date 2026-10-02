<?php

use LaravelNecromancer\Relationships\TestMatch;

test('each tested_by match has its display qualifier, empty for an exact match', function () {
    expect(TestMatch::Exact->qualifier())->toBe('')
        ->and(TestMatch::Namespace->qualifier())->toBe('namespace match')
        ->and(TestMatch::Reference->qualifier())->toBe('reference');
});

test('qualifierFor() maps a raw metadata value, and is empty for a missing or unknown one', function () {
    expect(TestMatch::qualifierFor('namespace'))->toBe('namespace match')
        ->and(TestMatch::qualifierFor('reference'))->toBe('reference')
        ->and(TestMatch::qualifierFor(null))->toBe('')
        ->and(TestMatch::qualifierFor('fuzzy'))->toBe('');
});
