<?php

use LaravelNecromancer\Okf\BundleExporter;
use LaravelNecromancer\Okf\Enrichment\BundleEnricher;
use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;
use LaravelNecromancer\Okf\Enrichment\EnrichmentCache;
use LaravelNecromancer\Okf\Enrichment\RawEnrichment;

function enricherTempDir(): string
{
    $dir = sys_get_temp_dir().'/necromancer-enricher-'.uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function removeEnricherTree(string $path): void
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

function enricherManifest(array $artifacts = [], ?string $contentHash = null): array
{
    return [
        'meta' => [
            'generated_at' => '2026-08-08T12:00:00+02:00',
            'scope' => ['complete' => true, 'artifact_types' => array_keys($artifacts)],
            'content_hash' => $contentHash,
        ],
        'artifacts' => $artifacts,
    ];
}

function fakeConceptEnricher(): ConceptEnricher
{
    return new class implements ConceptEnricher
    {
        public int $callCount = 0;

        /** @var list<string> */
        public array $prompts = [];

        public function enrich(string $prompt, ?string $provider = null, ?string $model = null, ?float $temperature = null): RawEnrichment
        {
            $this->callCount++;
            $this->prompts[] = $prompt;

            return new RawEnrichment('A generated description.', 'A generated narrative.', 10, 5);
        }
    };
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/necromancer-enricher-*') as $dir) {
        removeEnricherTree($dir);
    }
});

test('enrich() writes an enriched sibling bundle recording provider/model/prompt/privacy/cache provenance', function () {
    $output = enricherTempDir().'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'queue' => 'emails', 'source' => null]],
    ]);

    $enricher = fakeConceptEnricher();
    $cache = new EnrichmentCache(enricherTempDir());

    $result = (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: $enricher,
        cache: $cache,
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'excludes-source-framework-config-adr-bodies',
        promptVersion: '1',
        provider: 'anthropic',
        model: 'claude-sonnet-4-6',
        temperature: null,
    );

    expect($result->successful)->toBeTrue()
        ->and($result->conceptCount)->toBe(1)
        ->and($result->freshCount)->toBe(1)
        ->and($result->cachedCount)->toBe(0);

    $content = file_get_contents(glob($output.'/artifacts/*.md')[0]);

    expect($content)->toContain('provider: "anthropic"')
        ->and($content)->toContain('model: "claude-sonnet-4-6"')
        ->and($content)->toContain('prompt_version: "1"')
        ->and($content)->toContain('privacy_policy: "excludes-source-framework-config-adr-bodies"')
        ->and($content)->toContain('cached: false')
        ->and($content)->toContain('cache_key:')
        ->and($content)->toContain('A generated narrative.');
});

test('enrich() never fabricates a "default" provider/model label — it omits the fields entirely when none was given', function () {
    $output = enricherTempDir().'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache(enricherTempDir()),
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    $content = file_get_contents(glob($output.'/artifacts/*.md')[0]);

    expect($content)->not->toContain('provider: "default"')
        ->and($content)->not->toContain('model: "default"')
        ->and($content)->not->toContain('provider:')
        ->and($content)->not->toContain('model:');
});

test('enrich() reuses a cached result on a second run instead of calling the enricher again', function () {
    $base = enricherTempDir();
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    $enricher = fakeConceptEnricher();
    $bundleEnricher = new BundleEnricher;
    $args = [
        'manifest' => $manifest,
        'enricher' => $enricher,
        'cache' => new EnrichmentCache($base.'/cache'),
        'outputPath' => $base.'/okf-enriched',
        'basePath' => '',
        'stale' => false,
        'allowStale' => false,
        'allowPartial' => false,
        'privacyPolicy' => 'policy',
        'promptVersion' => '1',
        'provider' => null,
        'model' => null,
        'temperature' => null,
    ];

    $bundleEnricher->enrich(...$args);
    $args['cache'] = new EnrichmentCache($base.'/cache');
    $result = $bundleEnricher->enrich(...$args);

    expect($enricher->callCount)->toBe(1)
        ->and($result->cachedCount)->toBe(1)
        ->and($result->freshCount)->toBe(0);

    $content = file_get_contents(glob($base.'/okf-enriched/artifacts/*.md')[0]);
    expect($content)->toContain('cached: true');
});

test('enrich() with refresh:true bypasses the cache and calls the enricher again, then updates the cache', function () {
    $base = enricherTempDir();
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    $enricher = fakeConceptEnricher();
    $bundleEnricher = new BundleEnricher;
    $args = [
        'manifest' => $manifest,
        'enricher' => $enricher,
        'cache' => new EnrichmentCache($base.'/cache'),
        'outputPath' => $base.'/okf-enriched',
        'basePath' => '',
        'stale' => false,
        'allowStale' => false,
        'allowPartial' => false,
        'privacyPolicy' => 'policy',
        'promptVersion' => '1',
        'provider' => null,
        'model' => null,
        'temperature' => null,
    ];

    $bundleEnricher->enrich(...$args);
    $args['cache'] = new EnrichmentCache($base.'/cache');
    $result = $bundleEnricher->enrich(...[...$args, 'refresh' => true]);

    expect($enricher->callCount)->toBe(2)
        ->and($result->freshCount)->toBe(1)
        ->and($result->cachedCount)->toBe(0);
});

test('enrich() refuses a stale manifest with the same message as necromancer:okf', function () {
    $result = (new BundleEnricher)->enrich(
        manifest: enricherManifest([]),
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache(enricherTempDir()),
        outputPath: enricherTempDir().'/okf-enriched',
        basePath: '',
        stale: true,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('may be stale');
});

test('enrich() fails without writing anything when a declared local ADR file is missing', function () {
    $base = enricherTempDir();
    $output = $base.'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'annotations' => ['adrs' => ['docs/adr/missing.md']], 'source' => null]],
    ]);

    $result = (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $output,
        basePath: $base,
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('docs/adr/missing.md')
        ->and(is_dir($output))->toBeFalse();
});

test('enrich() never touches the primary (non-enriched) bundle directory', function () {
    $base = enricherTempDir();
    $primary = $base.'/okf';
    mkdir($primary.'/artifacts', 0755, true);
    file_put_contents($primary.'/artifacts/marker.md', 'do-not-touch');

    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $base.'/okf-enriched',
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    expect(file_get_contents($primary.'/artifacts/marker.md'))->toBe('do-not-touch')
        ->and(is_dir($base.'/okf-enriched'))->toBeTrue();
});

test('enrich() preserves facts, annotations, id, and relationship links from the deterministic bundle', function () {
    $base = enricherTempDir();
    $manifest = enricherManifest([
        'routes' => [['id' => 'routes:GET:orders', 'method' => 'GET', 'uri' => 'orders', 'controller' => 'App\\Http\\Controllers\\OrderController', 'annotations' => ['domain' => 'billing'], 'source' => null]],
        'policies' => [['id' => 'policies:App\\Http\\Controllers\\OrderController', 'class' => 'App\\Http\\Controllers\\OrderController', 'model' => 'App\\Models\\Order', 'source' => null]],
    ]);

    (new BundleExporter)->export($manifest, $base.'/okf', stale: false, allowStale: false, allowPartial: false);
    $plainContent = file_get_contents(glob($base.'/okf/artifacts/get-orders-*.md')[0]);

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $base.'/okf-enriched',
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    $enrichedContent = file_get_contents(glob($base.'/okf-enriched/artifacts/get-orders-*.md')[0]);

    // Same identity, same facts, same relationships, same declared annotations —
    // enrichment only adds a description field, an enrichment block, and a body section.
    expect($enrichedContent)->toContain('id: "routes:GET:orders"')
        ->and($enrichedContent)->toContain('domain: [billing]')
        ->and($enrichedContent)->toContain('## Relationships')
        ->and($enrichedContent)->toContain('- **controller**: [App\\Http\\Controllers\\OrderController]')
        ->and($enrichedContent)->toContain('## Discovered Facts')
        ->and($enrichedContent)->toContain('method: "GET"')
        ->and($enrichedContent)->toContain('uri: "orders"');

    expect($plainContent)->toContain('id: "routes:GET:orders"')
        ->and($plainContent)->toContain('domain: [billing]')
        ->and($plainContent)->toContain('- **controller**: [App\\Http\\Controllers\\OrderController]')
        ->and($plainContent)->toContain('method: "GET"')
        ->and($plainContent)->toContain('uri: "orders"')
        ->and($plainContent)->not->toContain('## AI-Enriched Summary');
});

test('enrich() records the manifest content_hash in the enriched bundle.json', function () {
    $base = enricherTempDir();
    $output = $base.'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ], contentHash: 'abc123');

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index['content_hash'])->toBe('abc123');
});

test('enrich() records a null content_hash when the manifest has none', function () {
    $base = enricherTempDir();
    $output = $base.'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    $index = json_decode(file_get_contents($output.'/bundle.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($index)->toHaveKey('content_hash')
        ->and($index['content_hash'])->toBeNull();
});

test('enrich() writes a README.md documenting enrichment specifics and mentioning the deterministic okf/ bundle', function () {
    $base = enricherTempDir();
    $output = $base.'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    $readme = file_get_contents($output.'/README.md');

    expect($readme)
        ->toContain('generated by Laravel Necromancer — do not edit manually')
        ->toContain('necromancer:okf-enrich')
        ->toContain('necromancer:okf')
        ->toContain('cach')
        ->toContain('privacy')
        ->toContain('provider')
        ->toContain('model')
        ->toContain('--refresh');
});

test('enrich() README footer reports generated_at and fresh/cached concept counts', function () {
    $base = enricherTempDir();
    $output = $base.'/okf-enriched';
    $manifest = enricherManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]);

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    $readme = file_get_contents($output.'/README.md');

    expect($readme)
        ->toContain('2026-08-08T12:00:00+02:00')
        ->toContain('1 fresh')
        ->toContain('0 cached');
});

test('enrich() leaves an existing enriched bundle README untouched when concept building fails', function () {
    $base = enricherTempDir();
    $output = $base.'/okf-enriched';
    mkdir($output.'/artifacts', 0755, true);
    file_put_contents($output.'/README.md', 'do-not-touch');

    // Duplicate ids resolve to the same concept filename, which
    // BundleExporter::assemble() (used for both the discovery and final
    // passes) refuses to overwrite silently.
    $manifest = enricherManifest([
        'jobs' => [
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
            ['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null],
        ],
    ]);

    $result = (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: fakeConceptEnricher(),
        cache: new EnrichmentCache($base.'/cache'),
        outputPath: $output,
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    expect($result->successful)->toBeFalse()
        ->and(file_get_contents($output.'/README.md'))->toBe('do-not-touch');
});

test('enrich() never sends source paths, hashes, or raw framework metadata to the enricher', function () {
    $manifest = enricherManifest([
        'routes' => [[
            'id' => 'routes:POST:billing/cancel',
            'method' => 'POST',
            'uri' => 'billing/cancel',
            'route_metadata' => ['raw' => ['head' => ['title' => 'Cancel']]],
            'source' => ['file' => 'app/Http/Controllers/BillingController.php', 'line' => 40, 'hash' => 'cafef00d'],
        ]],
    ]);

    $enricher = fakeConceptEnricher();

    (new BundleEnricher)->enrich(
        manifest: $manifest,
        enricher: $enricher,
        cache: new EnrichmentCache(enricherTempDir()),
        outputPath: enricherTempDir().'/okf-enriched',
        basePath: '',
        stale: false,
        allowStale: false,
        allowPartial: false,
        privacyPolicy: 'policy',
        promptVersion: '1',
        provider: null,
        model: null,
        temperature: null,
    );

    expect($enricher->prompts)->toHaveCount(1);
    $prompt = $enricher->prompts[0];

    expect($prompt)->not->toContain('cafef00d')
        ->and($prompt)->not->toContain('app/Http/Controllers/BillingController.php')
        ->and($prompt)->not->toContain('route_metadata')
        ->and($prompt)->not->toContain('"head"');
});
