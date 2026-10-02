<?php

use LaravelNecromancer\Manifest\ManifestScopeGuard;

/**
 * @return array<string, mixed>
 */
function scopedManifest(bool $complete): array
{
    return ['meta' => ['scope' => ['complete' => $complete]], 'artifacts' => []];
}

test('a fresh, complete manifest is accepted', function () {
    expect(ManifestScopeGuard::refusal(scopedManifest(true), false, false, false, 'go on'))->toBeNull();
});

test('a stale manifest is refused unless allowed, naming how to proceed', function () {
    expect(ManifestScopeGuard::refusal(scopedManifest(true), true, false, false, 'go on'))
        ->toBe('Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to go on.')
        ->and(ManifestScopeGuard::refusal(scopedManifest(true), true, true, false, 'go on'))->toBeNull();
});

test('a partial or scopeless manifest is refused unless allowed', function () {
    $partial = 'Manifest scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to go on.';

    expect(ManifestScopeGuard::refusal(scopedManifest(false), false, false, false, 'go on'))->toBe($partial)
        ->and(ManifestScopeGuard::refusal(['artifacts' => []], false, false, false, 'go on'))->toBe($partial)
        ->and(ManifestScopeGuard::refusal(scopedManifest(false), false, false, true, 'go on'))->toBeNull();
});
