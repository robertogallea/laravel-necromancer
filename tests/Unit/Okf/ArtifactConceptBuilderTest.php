<?php

use LaravelNecromancer\Okf\ArtifactConcept;
use LaravelNecromancer\Okf\ArtifactConceptBuilder;

function buildJobConcept(array $overrides = []): ArtifactConcept
{
    $artifact = array_merge([
        'id' => 'jobs:App\\Jobs\\SendInvoice',
        'class' => 'App\\Jobs\\SendInvoice',
        'queue' => 'emails',
        'tries' => 3,
        'source' => ['file' => 'app/Jobs/SendInvoice.php', 'line' => 12],
    ], $overrides);

    return (new ArtifactConceptBuilder)->build('jobs', $artifact, '2026-08-07T12:00:00+02:00');
}

test('build() derives the filename from a slugified title and an 8-character id hash', function () {
    $concept = buildJobConcept();

    $expectedHash = substr(hash('sha256', 'jobs:App\\Jobs\\SendInvoice'), 0, 8);
    expect($concept->filename)->toBe("app-jobs-sendinvoice-{$expectedHash}.md");
});

test('build() sets the front matter title, type, and kind', function () {
    $concept = buildJobConcept();

    expect($concept->content)->toContain('title: "App\\\\Jobs\\\\SendInvoice"')
        ->and($concept->content)->toContain('type: "artifact"')
        ->and($concept->content)->toContain('kind: "jobs"');
});

test('build() records the canonical id, schema version, and manifest generated_at under the necromancer namespace', function () {
    $concept = buildJobConcept();

    expect($concept->content)->toContain('id: "jobs:App\\\\Jobs\\\\SendInvoice"')
        ->and($concept->content)->toContain('schema_version: 1')
        ->and($concept->content)->toContain('generated_at: "2026-08-07T12:00:00+02:00"');
});

test('build() excludes id, annotations, source, and route_metadata from Discovered Facts', function () {
    $concept = buildJobConcept([
        'annotations' => ['domain' => 'billing'],
    ]);

    expect($concept->content)->toContain('queue: "emails"')
        ->and($concept->content)->toContain('tries: 3')
        ->and($concept->content)->not->toContain('facts:
    id:')
        ->and($concept->content)->not->toContain('facts:
    source:');
});

test('build() renders an Architectural Context section only when annotations are declared', function () {
    $withAnnotations = buildJobConcept(['annotations' => ['domain' => 'billing', 'risk' => 'high']]);
    $withoutAnnotations = buildJobConcept();

    expect($withAnnotations->content)->toContain('## Architectural Context')
        ->and($withAnnotations->content)->toContain('domain: billing · risk: high')
        ->and($withoutAnnotations->content)->not->toContain('## Architectural Context');
});

test('build() puts route_metadata under framework_metadata, never merged into facts', function () {
    $artifact = [
        'id' => 'routes:POST:billing/cancel',
        'method' => 'POST',
        'uri' => 'billing/cancel',
        'controller' => 'App\\Http\\Controllers\\BillingController',
        'route_metadata' => ['raw' => ['head' => ['title' => 'x']]],
        'source' => null,
    ];

    $concept = (new ArtifactConceptBuilder)->build('routes', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('framework_metadata:')
        ->and($concept->content)->not->toContain('facts:
    route_metadata:');
});

test('build() titles a route as method and uri', function () {
    $artifact = ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null];
    $concept = (new ArtifactConceptBuilder)->build('routes', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('title: "GET orders"');
});

test('build() titles a gate by its ability, with no class field', function () {
    $artifact = ['id' => 'gates:ability:edit-post', 'ability' => 'edit-post', 'kind' => 'closure', 'parameters' => [], 'source' => null];
    $concept = (new ArtifactConceptBuilder)->build('gates', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('title: "edit-post"');
});

test('build() titles a scheduled task by its command', function () {
    $artifact = ['id' => 'scheduled_tasks:abc123:1', 'command' => 'orders:prune', 'expression' => '0 0 * * *', 'source' => null];
    $concept = (new ArtifactConceptBuilder)->build('scheduled_tasks', $artifact, '2026-08-07T12:00:00+02:00');

    expect($concept->content)->toContain('title: "orders:prune"');
});

test('build() is deterministic: identical input produces byte-identical output', function () {
    $concept1 = buildJobConcept(['annotations' => ['domain' => 'billing', 'external_services' => ['stripe']]]);
    $concept2 = buildJobConcept(['annotations' => ['domain' => 'billing', 'external_services' => ['stripe']]]);

    expect($concept1->content)->toBe($concept2->content)
        ->and($concept1->filename)->toBe($concept2->filename);
});

test('build() exposes the artifact id on the concept value object', function () {
    $concept = buildJobConcept();

    expect($concept->id)->toBe('jobs:App\\Jobs\\SendInvoice');
});
