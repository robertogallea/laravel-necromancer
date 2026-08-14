<?php

use LaravelNecromancer\Okf\BundleExporter;
use LaravelNecromancer\Okf\ConceptEnrichment;

function okfTempDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-okf-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeTree(string $path): void
{
    if (! is_dir($path)) {
        @unlink($path);

        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

function completeManifest(array $artifacts = [], ?string $contentHash = null): array
{
    return [
        'meta' => [
            'generated_at' => '2026-08-07T12:00:00+02:00',
            'scope' => ['complete' => true, 'artifact_types' => array_keys($artifacts)],
            'content_hash' => $contentHash,
        ],
        'artifacts' => $artifacts,
    ];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/necromancer-okf-*') as $dir) {
        removeTree($dir);
    }
});

test('export() writes one file per artifact plus a bundle.json index', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
        'routes' => [
            ['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'source' => null],
        ],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->artifactCount)->toBe(2)
        ->and(is_dir($output.'/artifacts'))->toBeTrue()
        ->and(is_file($output.'/bundle.json'))->toBeTrue()
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(2);

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index['artifact_count'])->toBe(2)
        ->and($index['generated_at'])->toBe('2026-08-07T12:00:00+02:00');
});

test('export() refuses a stale manifest by default', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    $result = (new BundleExporter)->export($manifest, $output, stale: true, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('stale')
        ->and(is_dir($output))->toBeFalse();
});

test('export() proceeds on a stale manifest when allowStale is true', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    $result = (new BundleExporter)->export($manifest, $output, stale: true, allowStale: true, allowPartial: false);

    expect($result->successful)->toBeTrue();
});

test('export() refuses a partial-scope manifest by default', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);
    $manifest['meta']['scope']['complete'] = false;

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('partial')
        ->and(is_dir($output))->toBeFalse();
});

test('export() treats a manifest with no scope key at all as partial', function () {
    $output = okfTempDir().'/bundle';
    $manifest = ['meta' => ['generated_at' => '2026-08-07T12:00:00+02:00'], 'artifacts' => []];

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse();
});

test('export() proceeds on a partial-scope manifest when allowPartial is true', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);
    $manifest['meta']['scope']['complete'] = false;

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: true);

    expect($result->successful)->toBeTrue();
});

test('export() replaces a pre-existing bundle at the same output path', function () {
    $output = okfTempDir().'/bundle';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/artifacts/stale-concept.md', 'old');
    file_put_contents($output.'/bundle.json', '{"artifact_count": 99}');

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and(is_file($output.'/artifacts/stale-concept.md'))->toBeFalse()
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(1);
});

test('export() leaves an existing bundle untouched when concept building fails', function () {
    $output = okfTempDir().'/bundle';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/artifacts/marker.md', 'do-not-touch');
    file_put_contents($output.'/bundle.json', '{"artifact_count": 1}');

    // Two artifacts with the same id (malformed manifest) resolve to the
    // same concept filename, which the exporter must refuse to overwrite
    // silently — and it must not touch existing real output while doing so.
    $manifest = completeManifest([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and(is_file($output.'/artifacts/marker.md'))->toBeTrue()
        ->and(file_get_contents($output.'/bundle.json'))->toBe('{"artifact_count": 1}');
});

test('export() leaves an existing bundle README untouched when concept building fails', function () {
    $output = okfTempDir().'/bundle';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/README.md', 'do-not-touch');

    $manifest = completeManifest([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeFalse()
        ->and(file_get_contents($output.'/README.md'))->toBe('do-not-touch');
});

test('export() is deterministic: the same manifest produces byte-identical files across two runs', function () {
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null]],
    ]);

    $outputA = okfTempDir().'/bundle';
    $outputB = okfTempDir().'/bundle';

    (new BundleExporter)->export($manifest, $outputA, stale: false, allowStale: false, allowPartial: false);
    (new BundleExporter)->export($manifest, $outputB, stale: false, allowStale: false, allowPartial: false);

    $filesA = glob($outputA.'/artifacts/*.md');
    $filesB = glob($outputB.'/artifacts/*.md');

    expect(basename($filesA[0]))->toBe(basename($filesB[0]))
        ->and(file_get_contents($filesA[0]))->toBe(file_get_contents($filesB[0]))
        ->and(file_get_contents($outputA.'/README.md'))->toBe(file_get_contents($outputB.'/README.md'));
});

test('export() records the manifest content_hash in bundle.json', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([], contentHash: 'abc123');

    (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index['content_hash'])->toBe('abc123')
        ->and($index['okf_version'])->toBe('0.2');
});

test('export() records a null content_hash in bundle.json when the manifest has none', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index)->toHaveKey('content_hash')
        ->and($index['content_hash'])->toBeNull();
});

test('export() writes a README.md documenting necromancer:okf and mentioning necromancer:okf-enrich', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $readme = file_get_contents($output.'/README.md');

    expect($readme)
        ->toContain('generated by Laravel Necromancer — do not edit manually')
        ->toContain('necromancer:okf')
        ->toContain('necromancer:okf-enrich')
        ->toContain('Artifact Concept')
        ->toContain('Domain Concept')
        ->toContain('Flow Concept')
        ->toContain('ADR Concept')
        ->toContain('necromancer.okf.output')
        ->toContain('necromancer.okf.enrichment.output');
});

test('export() README footer reports generated_at and artifact_count', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $readme = file_get_contents($output.'/README.md');

    expect($readme)
        ->toContain('2026-08-07T12:00:00+02:00')
        ->toContain('1 artifact concept');
});

test('validateScope() returns the same stale/partial messages export() would fail with', function () {
    $exporter = new BundleExporter;
    $manifest = completeManifest([]);

    expect($exporter->validateScope($manifest, stale: true, allowStale: false, allowPartial: false))
        ->toContain('may be stale');

    $partial = $manifest;
    $partial['meta']['scope']['complete'] = false;
    expect($exporter->validateScope($partial, stale: false, allowStale: false, allowPartial: false))
        ->toContain('scope is partial');

    expect($exporter->validateScope($manifest, stale: false, allowStale: false, allowPartial: false))
        ->toBeNull();
});

test('assemble() returns the artifact, group, and adr concepts separately, plus identities', function () {
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null]],
    ]);

    $assembled = (new BundleExporter)->assemble($manifest, '2026-08-07T12:00:00+02:00', '');

    expect($assembled['artifact'])->toHaveCount(1)
        ->and($assembled['group'])->toHaveCount(1)
        ->and($assembled['adr'])->toHaveCount(0)
        ->and($assembled['identities'])->toHaveKey('jobs:App\\Jobs\\SendInvoice');
});

test('assemble() produces byte-identical Artifact Concept content to what export() writes to disk', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null]],
    ]);

    $exporter = new BundleExporter;
    $assembled = $exporter->assemble($manifest, '2026-08-07T12:00:00+02:00', '');
    $exporter->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    $writtenContent = file_get_contents(glob($output.'/artifacts/app-jobs-sendinvoice-*.md')[0]);

    expect($assembled['artifact'][0]->content."\n")->toBe($writtenContent);
});

test('assemble() attaches a passed-in enrichment to the matching concept by id, for every concept kind', function () {
    $base = okfTempDir();
    mkdir($base.'/docs/adr', 0755, true);
    file_put_contents($base.'/docs/adr/0004-x.md', 'ADR body.');

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing', 'adrs' => ['docs/adr/0004-x.md']], 'source' => null]],
    ]);

    $jobEnrichment = new ConceptEnrichment('Job description.', 'Job narrative.', 'anthropic', 'sonnet', '1', 'policy', 'key', false);
    $domainEnrichment = new ConceptEnrichment('Domain description.', 'Domain narrative.', 'anthropic', 'sonnet', '1', 'policy', 'key', false);
    $adrEnrichment = new ConceptEnrichment('ADR description.', 'ADR narrative.', 'anthropic', 'sonnet', '1', 'policy', 'key', false);

    $assembled = (new BundleExporter)->assemble($manifest, '2026-08-07T12:00:00+02:00', $base, [
        'jobs:App\\Jobs\\SendInvoice' => $jobEnrichment,
        'domain:billing' => $domainEnrichment,
        'adr:docs/adr/0004-x.md' => $adrEnrichment,
    ]);

    expect($assembled['artifact'][0]->content)->toContain('Job narrative.')
        ->and($assembled['group'][0]->content)->toContain('Domain narrative.')
        ->and($assembled['adr'][0]->content)->toContain('ADR narrative.');
});

test('assemble() throws when a declared local ADR file is missing, the same as export() fails', function () {
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['adrs' => ['docs/adr/missing.md']], 'source' => null]],
    ]);

    (new BundleExporter)->assemble($manifest, '2026-08-07T12:00:00+02:00', okfTempDir());
})->throws(RuntimeException::class, 'docs/adr/missing.md');

test('export() succeeds for an empty manifest with zero artifacts', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->artifactCount)->toBe(0)
        ->and(is_dir($output.'/artifacts'))->toBeTrue();
});

test('export() synthesizes a Domain Concept grouping every artifact sharing that domain', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null]],
        'routes' => [['id' => 'routes:POST:billing/cancel', 'method' => 'POST', 'uri' => 'billing/cancel', 'annotations' => ['domain' => 'billing'], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->artifactCount)->toBe(2);

    $domainHash = substr(hash('sha256', 'domain:billing'), 0, 8);
    $domainFile = $output."/artifacts/billing-{$domainHash}.md";

    expect(is_file($domainFile))->toBeTrue();

    $content = file_get_contents($domainFile);
    expect($content)->toContain('type: "domain"')
        ->and($content)->toContain('"jobs:App\\\\Jobs\\\\SendInvoice"')
        ->and($content)->toContain('"routes:POST:billing/cancel"')
        ->and($content)->toContain('- [App\\Jobs\\SendInvoice]')
        ->and($content)->toContain('- [POST billing/cancel]');
});

test('export() synthesizes a Flow Concept grouping every artifact sharing that flow', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['flow' => 'subscription-cancellation'], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue();

    $flowHash = substr(hash('sha256', 'flow:subscription-cancellation'), 0, 8);
    $flowFile = $output."/artifacts/subscription-cancellation-{$flowHash}.md";

    expect(is_file($flowFile))->toBeTrue()
        ->and(file_get_contents($flowFile))->toContain('type: "flow"');
});

test('export() links an artifact concept back to its own Domain Concept', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing'], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue();

    $domainHash = substr(hash('sha256', 'domain:billing'), 0, 8);
    $jobContent = file_get_contents(glob($output.'/artifacts/app-jobs-sendinvoice-*.md')[0]);

    expect($jobContent)->toContain("domain: [billing](/artifacts/billing-{$domainHash}.md)");
});

test('export() does not synthesize a group concept for an unannotated manifest', function () {
    $output = okfTempDir().'/bundle';
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(1);
});

test('export() links a resolvable relationship across artifact concepts', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'routes' => [['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'source' => null]],
        'policies' => [['id' => 'policies:App\\Http\\Controllers\\OrderController', 'class' => 'App\\Http\\Controllers\\OrderController', 'model' => 'App\\Models\\Order', 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue();

    $routeContent = file_get_contents(glob($output.'/artifacts/get-orders-*.md')[0]);
    expect($routeContent)->toContain('## Relationships')
        ->and($routeContent)->toContain('- **controller**: [App\\Http\\Controllers\\OrderController](/artifacts/');
});

test('export() copies a declared local ADR into the bundle with provenance and links referencing artifacts', function () {
    $base = okfTempDir();
    mkdir($base.'/docs/adr', 0755, true);
    file_put_contents($base.'/docs/adr/0004-x.md', "# ADR 0004\n\nDecision text.");

    $output = $base.'/bundle';
    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['adrs' => ['docs/adr/0004-x.md']], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false, basePath: $base);

    expect($result->successful)->toBeTrue();

    $adrHash = substr(hash('sha256', 'adr:docs/adr/0004-x.md'), 0, 8);
    $adrFile = $output."/artifacts/0004-x-{$adrHash}.md";

    expect(is_file($adrFile))->toBeTrue();
    $adrContent = file_get_contents($adrFile);
    expect($adrContent)->toContain('file: "docs/adr/0004-x.md"')
        ->and($adrContent)->toContain('Decision text.')
        ->and($adrContent)->toContain('- [App\\Jobs\\SendInvoice]');

    $jobFile = glob($output.'/artifacts/app-jobs-sendinvoice-*.md')[0];
    expect(file_get_contents($jobFile))->toContain("adrs: [docs/adr/0004-x.md](/artifacts/0004-x-{$adrHash}.md)");
});

test('export() fails without writing anything when a declared local ADR file is missing', function () {
    $base = okfTempDir();
    $output = $base.'/bundle';

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['adrs' => ['docs/adr/missing.md']], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false, basePath: $base);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('docs/adr/missing.md')
        ->and(is_dir($output))->toBeFalse();
});

test('export() reports artifact_count in bundle.json as only the Artifact Concepts, not the synthesized ones', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['domain' => 'billing', 'flow' => 'invoicing'], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and($result->artifactCount)->toBe(1)
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(3);

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index['artifact_count'])->toBe(1);
});

test('export() treats an absolute ADR URI as an external link, never a local file to copy', function () {
    $output = okfTempDir().'/bundle';

    $manifest = completeManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['adrs' => ['https://example.com/adr/1']], 'source' => null]],
    ]);

    $result = (new BundleExporter)->export($manifest, $output, stale: false, allowStale: false, allowPartial: false);

    expect($result->successful)->toBeTrue()
        ->and(count(glob($output.'/artifacts/*.md')))->toBe(1);
});
