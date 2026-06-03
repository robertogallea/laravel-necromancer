<?php

use Illuminate\Support\Facades\File;
use LaravelNecromancer\Inference\Contracts\AdrCritic;
use LaravelNecromancer\Inference\Contracts\AdrInferrer;
use LaravelNecromancer\Inference\Contracts\AdrTranslator;
use LaravelNecromancer\Inference\InferredAdr;
use LaravelNecromancer\Integrations\AiDetector;

function inferCommandManifest(?string $generatedAt = null): string
{
    $artifacts = [
        'jobs' => [
            ['class' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'tries' => 3],
        ],
    ];

    return json_encode([
        'meta' => [
            'app_name'        => 'TestApp',
            'generated_at'    => $generatedAt ?? now()->toISOString(),
            'content_hash'    => hash('sha256', json_encode($artifacts, JSON_THROW_ON_ERROR)),
            'laravel_version' => '13.0',
            'php_version'     => '8.4',
        ],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR);
}

function fakeInferrer(): AdrInferrer
{
    return new class implements AdrInferrer {
        public ?string $lastLocale = 'sentinel';
        public mixed $lastTemperature = false;
        public int $callCount = 0;

        public function infer(string $prompt, ?string $provider = null, ?string $model = null, ?string $locale = null, ?float $temperature = null): \LaravelNecromancer\Inference\AdrInferenceResult
        {
            $this->lastLocale = $locale;
            $this->lastTemperature = $temperature;
            $this->callCount++;

            return new \LaravelNecromancer\Inference\AdrInferenceResult(
                adrs: [
                    new InferredAdr(
                        title: 'Async Email Delivery',
                        slug: 'async-email-delivery',
                        status: 'accepted',
                        context: 'Email delivery blocks HTTP responses.',
                        decision: 'Use queued jobs on the emails queue.',
                        consequences: 'Workers must be running.',
                    ),
                ],
                promptTokens: 100,
                completionTokens: 50,
            );
        }
    };
}

function fakeTranslator(): AdrTranslator
{
    return new class implements AdrTranslator {
        public ?string $lastLocale = 'sentinel';
        public int $callCount = 0;

        /** @param list<InferredAdr> $adrs */
        public function translate(array $adrs, string $targetLocale, ?string $provider = null, ?string $model = null, ?float $temperature = null): \LaravelNecromancer\Inference\AdrInferenceResult
        {
            $this->lastLocale = $targetLocale;
            $this->callCount++;

            $translated = array_map(
                fn (InferredAdr $adr) => new InferredAdr(
                    title: "[{$targetLocale}] {$adr->title}",
                    slug: $adr->slug,
                    status: $adr->status,
                    context: $adr->context,
                    decision: $adr->decision,
                    consequences: $adr->consequences,
                ),
                $adrs,
            );

            return new \LaravelNecromancer\Inference\AdrInferenceResult(
                adrs: $translated,
                promptTokens: 80,
                completionTokens: 40,
            );
        }
    };
}

function fakeCritic(bool $satisfied = true): AdrCritic
{
    return new class ($satisfied) implements AdrCritic {
        public int $callCount = 0;

        public function __construct(private readonly bool $satisfied) {}

        /** @param list<InferredAdr> $adrs */
        public function critique(array $adrs, string $manifestSummary, ?string $provider = null, ?string $model = null, ?float $temperature = null): \LaravelNecromancer\Inference\AdrCriticResult
        {
            $this->callCount++;

            return new \LaravelNecromancer\Inference\AdrCriticResult(
                adrs: $adrs,
                satisfied: $this->satisfied,
                promptTokens: 60,
                completionTokens: 30,
            );
        }
    };
}

function aiAvailable(): AiDetector
{
    return new AiDetector(\Illuminate\Support\ServiceProvider::class);
}

function aiAbsent(): AiDetector
{
    return new AiDetector('NonExistent\\Ai\\AiServiceProvider');
}

beforeEach(function () {
    $this->instance(AiDetector::class, aiAvailable());
    $this->instance(AdrInferrer::class, fakeInferrer());
    $this->instance(AdrTranslator::class, fakeTranslator());
    $this->instance(AdrCritic::class, fakeCritic());

    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('docs/adr/necromancer'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
    File::deleteDirectory(base_path('docs/adr'));
});

test('the infer command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:infer')
        ->assertSuccessful();
});

test('the infer command fails when the manifest is missing', function () {
    $this->artisan('necromancer:infer')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('the infer command fails with instructions when laravel/ai is not installed', function () {
    $this->instance(AiDetector::class, aiAbsent());
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer')
        ->expectsOutputToContain('laravel/ai is not installed')
        ->expectsOutputToContain('composer require laravel/ai')
        ->assertFailed();
});

test('the infer command writes ADR files on success', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer')->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBeTrue();
});

test('the infer command prints the path of each written file', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer')
        ->expectsOutputToContain('0001-async-email-delivery.md')
        ->assertSuccessful();
});

test('the infer command with --dry-run prints content but writes no files', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer', ['--dry-run' => true])
        ->expectsOutputToContain('Async Email Delivery')
        ->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBeFalse();
});

test('the infer command asks for confirmation before overwriting an existing ADR', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());
    File::ensureDirectoryExists(base_path('docs/adr/necromancer'));
    File::put(base_path('docs/adr/necromancer/0001-async-email-delivery.md'), 'old');

    $this->artisan('necromancer:infer')
        ->expectsConfirmation('async-email-delivery already exists. Overwrite?', 'no')
        ->assertFailed();

    expect(File::get(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBe('old');
});

test('the infer command with --force overwrites existing ADRs without confirmation', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());
    File::ensureDirectoryExists(base_path('docs/adr/necromancer'));
    File::put(base_path('docs/adr/necromancer/0001-async-email-delivery.md'), 'old');

    $this->artisan('necromancer:infer', ['--force' => true])->assertSuccessful();

    expect(File::get(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))
        ->not->toBe('old')
        ->toContain('# ADR 0001: Async Email Delivery');
});

test('the infer command with --fresh deletes existing ADRs before writing', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());
    File::ensureDirectoryExists(base_path('docs/adr/necromancer'));
    File::put(base_path('docs/adr/necromancer/0001-old-decision.md'), 'old');

    $this->artisan('necromancer:infer', ['--fresh' => true])->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/0001-old-decision.md')))->toBeFalse();
    expect(File::exists(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBeTrue();
});

test('the infer command prints total token usage after a successful run', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    // inference (150) + critic (90) = 240
    $this->artisan('necromancer:infer')
        ->expectsOutputToContain('240')
        ->assertSuccessful();
});

test('without --locale the inferrer receives the app locale', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer')->assertSuccessful();

    expect($inferrer->lastLocale)->toBe(app()->getLocale());
});

test('with --locale the canonical ADRs are always written to the flat output directory', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer', ['--locale' => 'it'])->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBeTrue();
});

test('with --locale the translated ADRs are written to a locale subfolder', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer', ['--locale' => 'it'])->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/it/0001-async-email-delivery.md')))->toBeTrue();
});

test('with --locale the translator receives the target locale', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $translator = fakeTranslator();
    $this->instance(AdrTranslator::class, $translator);

    $this->artisan('necromancer:infer', ['--locale' => 'it'])->assertSuccessful();

    expect($translator->lastLocale)->toBe('it');
});

test('with --locale the inferrer always receives the default app locale', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer', ['--locale' => 'it'])->assertSuccessful();

    expect($inferrer->lastLocale)->toBe(app()->getLocale());
});

test('with multiple locales canonical is flat and each extra locale gets a subfolder', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $this->artisan('necromancer:infer', ['--locale' => 'fr,it'])->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBeTrue();
    expect(File::exists(base_path('docs/adr/necromancer/fr/0001-async-email-delivery.md')))->toBeTrue();
    expect(File::exists(base_path('docs/adr/necromancer/it/0001-async-email-delivery.md')))->toBeTrue();
});

test('token count covers canonical inference plus critic plus all translation calls', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    // inference (150) + critic (90) + 2 translations (120 each) = 480
    $this->artisan('necromancer:infer', ['--locale' => 'fr,it'])
        ->expectsOutputToContain('480')
        ->assertSuccessful();
});

test('locale that matches the default app locale is skipped as an extra locale', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $translator = fakeTranslator();
    $this->instance(AdrTranslator::class, $translator);

    $this->artisan('necromancer:infer', ['--locale' => app()->getLocale()])->assertSuccessful();

    expect($translator->lastLocale)->toBe('sentinel');
    expect(File::exists(base_path('docs/adr/necromancer/'.app()->getLocale().'/0001-async-email-delivery.md')))->toBeFalse();
});

test('with --locale --fresh deletes existing canonical and locale ADRs before writing', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());
    File::ensureDirectoryExists(base_path('docs/adr/necromancer'));
    File::put(base_path('docs/adr/necromancer/0001-old-decision.md'), 'old-canonical');
    File::ensureDirectoryExists(base_path('docs/adr/necromancer/it'));
    File::put(base_path('docs/adr/necromancer/it/0001-old-decision.md'), 'old-it');

    $this->artisan('necromancer:infer', ['--locale' => 'it', '--fresh' => true])->assertSuccessful();

    expect(File::exists(base_path('docs/adr/necromancer/0001-old-decision.md')))->toBeFalse();
    expect(File::exists(base_path('docs/adr/necromancer/it/0001-old-decision.md')))->toBeFalse();
    expect(File::exists(base_path('docs/adr/necromancer/0001-async-email-delivery.md')))->toBeTrue();
    expect(File::exists(base_path('docs/adr/necromancer/it/0001-async-email-delivery.md')))->toBeTrue();
});

test('with --temperature the infer command passes temperature to the inferrer', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer', ['--temperature' => '0'])->assertSuccessful();

    expect($inferrer->lastTemperature)->toBe(0.0);
});

test('without --temperature the inferrer receives null temperature', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer')->assertSuccessful();

    expect($inferrer->lastTemperature)->toBeNull();
});

test('a second run with unchanged manifest uses the cached canonical ADRs', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer')->assertSuccessful();
    $callCount = $inferrer->callCount;

    $this->artisan('necromancer:infer', ['--force' => true])->assertSuccessful();

    expect($inferrer->callCount)->toBe($callCount);
});

test('the cache is hit when generated_at changes but content_hash is unchanged', function () {
    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    File::put(base_path('necromancer.json'), inferCommandManifest('2026-01-01T00:00:00+00:00'));
    $this->artisan('necromancer:infer')->assertSuccessful();
    $callCountAfterFirst = $inferrer->callCount;

    File::put(base_path('necromancer.json'), inferCommandManifest('2030-01-01T00:00:00+00:00'));
    $this->artisan('necromancer:infer', ['--force' => true])->assertSuccessful();

    expect($inferrer->callCount)->toBe($callCountAfterFirst);
});

test('with --refresh the cache is bypassed and inferrer is called again', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer')->assertSuccessful();
    $callCountAfterFirst = $inferrer->callCount;

    $this->artisan('necromancer:infer', ['--refresh' => true, '--force' => true])->assertSuccessful();

    expect($inferrer->callCount)->toBeGreaterThan($callCountAfterFirst);
});

test('adding a new locale reuses cached canonical and only calls translator', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $translator = fakeTranslator();
    $this->instance(AdrInferrer::class, $inferrer);
    $this->instance(AdrTranslator::class, $translator);

    $this->artisan('necromancer:infer')->assertSuccessful();
    $inferCallCount = $inferrer->callCount;

    $this->artisan('necromancer:infer', ['--locale' => 'it', '--force' => true])->assertSuccessful();

    expect($inferrer->callCount)->toBe($inferCallCount);
    expect($translator->callCount)->toBeGreaterThan(0);
});

test('with --fresh the cache is invalidated and inference runs again', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $this->instance(AdrInferrer::class, $inferrer);

    $this->artisan('necromancer:infer')->assertSuccessful();
    $callCountAfterFirst = $inferrer->callCount;

    $this->artisan('necromancer:infer', ['--fresh' => true])->assertSuccessful();

    expect($inferrer->callCount)->toBeGreaterThan($callCountAfterFirst);
});

test('with critic enabled the critic agent is called after inference', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $critic = fakeCritic();
    $this->instance(AdrCritic::class, $critic);

    $this->artisan('necromancer:infer')->assertSuccessful();

    expect($critic->callCount)->toBeGreaterThan(0);
});

test('with critic disabled via config the critic agent is not called', function () {
    config(['necromancer.inference.critic.enabled' => false]);
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $critic = fakeCritic();
    $this->instance(AdrCritic::class, $critic);

    $this->artisan('necromancer:infer')->assertSuccessful();

    expect($critic->callCount)->toBe(0);
});

test('critic token usage is included in the reported total', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    // inference = 150 tokens, critic = 90 tokens → total = 240
    $this->artisan('necromancer:infer')
        ->expectsOutputToContain('240')
        ->assertSuccessful();
});

test('with critic enabled the cache stores post-critic ADRs', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $inferrer = fakeInferrer();
    $critic = fakeCritic();
    $this->instance(AdrInferrer::class, $inferrer);
    $this->instance(AdrCritic::class, $critic);

    $this->artisan('necromancer:infer')->assertSuccessful();
    $inferCount = $inferrer->callCount;
    $criticCount = $critic->callCount;

    $this->artisan('necromancer:infer', ['--force' => true])->assertSuccessful();

    expect($inferrer->callCount)->toBe($inferCount);
    expect($critic->callCount)->toBe($criticCount);
});

test('with --max-critic-rounds=2 and satisfied=false on first round the critic is called twice', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $critic = fakeCritic(satisfied: false);
    $this->instance(AdrCritic::class, $critic);

    $this->artisan('necromancer:infer', ['--max-critic-rounds' => '2'])->assertSuccessful();

    expect($critic->callCount)->toBe(2);
});

test('with --max-critic-rounds=2 but satisfied=true on first round only one round is run', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $critic = fakeCritic(satisfied: true);
    $this->instance(AdrCritic::class, $critic);

    $this->artisan('necromancer:infer', ['--max-critic-rounds' => '2'])->assertSuccessful();

    expect($critic->callCount)->toBe(1);
});

test('with --max-critic-rounds=2 and 2 unsatisfied rounds all round tokens are included in the total', function () {
    File::put(base_path('necromancer.json'), inferCommandManifest());

    $critic = fakeCritic(satisfied: false);
    $this->instance(AdrCritic::class, $critic);

    // inference (150) + critic round 1 (90) + critic round 2 (90) = 330
    $this->artisan('necromancer:infer', ['--max-critic-rounds' => '2'])
        ->expectsOutputToContain('330')
        ->assertSuccessful();
});
