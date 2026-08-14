<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use LaravelNecromancer\Integrations\AiDetector;
use LaravelNecromancer\Okf\Enrichment\Contracts\ConceptEnricher;
use LaravelNecromancer\Okf\Enrichment\RawEnrichment;

function okfEnrichManifest(array $artifacts = [], bool $complete = true, ?string $contentHash = null): array
{
    return [
        'meta' => [
            'generated_at' => now()->addMinute()->toIso8601String(),
            'scope' => ['complete' => $complete, 'artifact_types' => array_keys($artifacts)],
            'content_hash' => $contentHash,
        ],
        'artifacts' => $artifacts,
    ];
}

function fakeConceptEnricherForCommand(): ConceptEnricher
{
    return new class implements ConceptEnricher
    {
        public int $callCount = 0;

        public ?string $lastProvider = 'sentinel';

        public ?string $lastModel = 'sentinel';

        public function enrich(string $prompt, ?string $provider = null, ?string $model = null, ?float $temperature = null): RawEnrichment
        {
            $this->callCount++;
            $this->lastProvider = $provider;
            $this->lastModel = $model;

            return new RawEnrichment('A generated description.', 'A generated narrative.', 10, 5);
        }
    };
}

function aiAvailableForOkfEnrich(): AiDetector
{
    return new AiDetector(ServiceProvider::class);
}

function aiAbsentForOkfEnrich(): AiDetector
{
    return new AiDetector('NonExistent\\Ai\\AiServiceProvider');
}

beforeEach(function () {
    $this->instance(AiDetector::class, aiAvailableForOkfEnrich());
    $this->instance(ConceptEnricher::class, fakeConceptEnricherForCommand());

    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('okf-enriched'));
    File::deleteDirectory(storage_path('app/necromancer/okf-enrichment-cache'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('okf-enriched'));
    File::deleteDirectory(storage_path('app/necromancer/okf-enrichment-cache'));
});

test('the okf-enrich command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:okf-enrich')
        ->assertSuccessful();
});

test('the okf-enrich command fails with a clear message when the manifest is absent', function () {
    $this->artisan('necromancer:okf-enrich')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the okf-enrich command fails with instructions when laravel/ai is not installed', function () {
    $this->instance(AiDetector::class, aiAbsentForOkfEnrich());
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich')
        ->expectsOutputToContain('laravel/ai is not installed')
        ->expectsOutputToContain('composer require laravel/ai')
        ->assertFailed();
});

test('the okf-enrich command writes an enriched bundle to the default sibling output directory', function () {
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich')
        ->expectsOutputToContain('Enriched 1 concept(s)')
        ->assertSuccessful();

    expect(File::isDirectory(base_path('okf-enriched/artifacts')))->toBeTrue()
        ->and(count(File::glob(base_path('okf-enriched/artifacts/*.md'))))->toBe(1);

    $content = File::get(File::glob(base_path('okf-enriched/artifacts/*.md'))[0]);
    expect($content)->toContain('A generated narrative.')
        ->and($content)->toContain('cache_key:');
});

test('the okf-enrich command writes a README.md documenting enrichment and the deterministic sibling', function () {
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ], contentHash: 'abc123'), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich')->assertSuccessful();

    expect(File::isFile(base_path('okf-enriched/README.md')))->toBeTrue();

    $readme = File::get(base_path('okf-enriched/README.md'));

    expect($readme)
        ->toContain('necromancer:okf')
        ->toContain('1 fresh');
});

test('the okf-enrich command records the manifest content_hash in the enriched bundle.json', function () {
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([], contentHash: 'abc123'), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich')->assertSuccessful();

    $index = json_decode(File::get(base_path('okf-enriched/bundle.json')), true, 512, JSON_THROW_ON_ERROR);
    expect($index['content_hash'])->toBe('abc123');
});

test('--output overrides the default enriched bundle directory', function () {
    $customPath = storage_path('framework/testing/okf-enrich-custom');
    File::deleteDirectory($customPath);

    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich', ['--output' => $customPath])
        ->assertSuccessful();

    expect(File::isDirectory($customPath.'/artifacts'))->toBeTrue()
        ->and(File::isDirectory(base_path('okf-enriched')))->toBeFalse();

    File::deleteDirectory($customPath);
});

test('the okf-enrich command refuses a stale manifest by default', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');

    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => [],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich')
        ->expectsOutputToContain('may be stale')
        ->assertFailed();

    expect(File::isDirectory(base_path('okf-enriched')))->toBeFalse();

    File::deleteDirectory(base_path('app'));
});

test('the okf-enrich command refuses a partial-scope manifest by default', function () {
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([], complete: false), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich')
        ->expectsOutputToContain('scope is partial')
        ->assertFailed();
});

test('--provider and --model override config and are passed through to the enricher', function () {
    $enricher = fakeConceptEnricherForCommand();
    $this->instance(ConceptEnricher::class, $enricher);

    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:okf-enrich', ['--provider' => 'anthropic', '--model' => 'claude-sonnet-4-6'])
        ->assertSuccessful();

    expect($enricher->lastProvider)->toBe('anthropic')
        ->and($enricher->lastModel)->toBe('claude-sonnet-4-6');
});

test('running the command twice reuses the cache on the second run', function () {
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $enricher = fakeConceptEnricherForCommand();
    $this->instance(ConceptEnricher::class, $enricher);

    $this->artisan('necromancer:okf-enrich')->assertSuccessful();
    $this->artisan('necromancer:okf-enrich')
        ->expectsOutputToContain('1 cached')
        ->assertSuccessful();

    expect($enricher->callCount)->toBe(1);
});

test('--refresh bypasses the cache and calls the enricher again', function () {
    File::put(base_path('necromancer.json'), json_encode(okfEnrichManifest([
        'jobs' => [['id' => 'jobs:App\\Jobs\\SendInvoice', 'class' => 'App\\Jobs\\SendInvoice', 'source' => null]],
    ]), JSON_THROW_ON_ERROR));

    $enricher = fakeConceptEnricherForCommand();
    $this->instance(ConceptEnricher::class, $enricher);

    $this->artisan('necromancer:okf-enrich')->assertSuccessful();
    $this->artisan('necromancer:okf-enrich', ['--refresh' => true])->assertSuccessful();

    expect($enricher->callCount)->toBe(2);
});
