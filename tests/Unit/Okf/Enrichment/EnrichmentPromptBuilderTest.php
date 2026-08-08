<?php

use LaravelNecromancer\Okf\Enrichment\EnrichmentPromptBuilder;

test('forArtifact() includes facts and annotations relevant to the artifact', function () {
    $artifact = [
        'id' => 'jobs:App\\Jobs\\SendInvoice',
        'class' => 'App\\Jobs\\SendInvoice',
        'queue' => 'emails',
        'tries' => 3,
        'annotations' => ['domain' => 'billing', 'risk' => 'high'],
        'source' => ['file' => 'app/Jobs/SendInvoice.php', 'line' => 12, 'hash' => 'deadbeef'],
    ];

    $prompt = (new EnrichmentPromptBuilder)->forArtifact('jobs', $artifact);

    expect($prompt)->toContain('SendInvoice')
        ->and($prompt)->toContain('emails')
        ->and($prompt)->toContain('billing')
        ->and($prompt)->toContain('high');
});

test('forArtifact() excludes raw framework metadata, source paths, and hashes', function () {
    $artifact = [
        'id' => 'routes:POST:billing/cancel',
        'method' => 'POST',
        'uri' => 'billing/cancel',
        'route_metadata' => ['raw' => ['head' => ['title' => 'Cancel']], 'necromancer' => ['domain' => 'billing']],
        'source' => ['file' => 'app/Http/Controllers/BillingController.php', 'line' => 40, 'hash' => 'cafef00d'],
    ];

    $prompt = (new EnrichmentPromptBuilder)->forArtifact('routes', $artifact);

    expect($prompt)->not->toContain('route_metadata')
        ->and($prompt)->not->toContain('head')
        ->and($prompt)->not->toContain('app/Http/Controllers/BillingController.php')
        ->and($prompt)->not->toContain('cafef00d')
        ->and($prompt)->not->toContain('"source"');
});

test('forArtifact() never includes configuration values, since manifest artifacts never carry any', function () {
    $artifact = ['id' => 'jobs:App\\Jobs\\X', 'class' => 'App\\Jobs\\X'];

    $prompt = (new EnrichmentPromptBuilder)->forArtifact('jobs', $artifact);

    expect($prompt)->not->toContain('config(')
        ->and($prompt)->not->toContain('env(');
});

test('forGroup() includes the kind, value, and member ids only', function () {
    $prompt = (new EnrichmentPromptBuilder)->forGroup('domain', 'billing', ['jobs:App\\Jobs\\X', 'routes:GET:orders']);

    expect($prompt)->toContain('domain')
        ->and($prompt)->toContain('billing')
        ->and($prompt)->toContain('jobs:App')
        ->and($prompt)->toContain('routes:GET:orders');
});

test('forAdr() includes the path and referencing artifact ids — its signature has no parameter for the ADR body at all', function () {
    $prompt = (new EnrichmentPromptBuilder)->forAdr('docs/adr/0004-x.md', ['jobs:App\\Jobs\\X']);

    expect($prompt)->toContain('docs/adr/0004-x.md')
        ->and($prompt)->toContain('jobs:App');

    expect((new ReflectionMethod(EnrichmentPromptBuilder::class, 'forAdr'))->getNumberOfParameters())->toBe(2);
});

test('forArtifact() is deterministic: identical input produces byte-identical output', function () {
    $artifact = ['id' => 'jobs:App\\Jobs\\X', 'class' => 'App\\Jobs\\X', 'queue' => 'default'];
    $builder = new EnrichmentPromptBuilder;

    expect($builder->forArtifact('jobs', $artifact))->toBe($builder->forArtifact('jobs', $artifact));
});
