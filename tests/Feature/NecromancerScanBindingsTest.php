<?php

use Illuminate\Support\Facades\File;
use LaravelNecromancer\Collection\ActionCollector;
use LaravelNecromancer\Manifest\ScanManifest;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerInvoiceNumbers;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerPaymentGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Contracts\NecromancerRefundGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Http\NecromancerRefundController;
use LaravelNecromancer\Tests\Fixtures\Bindings\Http\NecromancerReportController;
use LaravelNecromancer\Tests\Fixtures\Bindings\Logging\NecromancerAuditLogger;
use LaravelNecromancer\Tests\Fixtures\Bindings\Providers\NecromancerCodeServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Providers\NecromancerDeferredServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Providers\NecromancerPaymentServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Providers\NecromancerUnloadedDeferredServiceProvider;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerFakeInvoiceNumbers;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerFakePaymentGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerLedger;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerSequentialInvoiceNumbers;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerStripeGateway;
use LaravelNecromancer\Tests\Fixtures\Bindings\Services\NecromancerStripeRefunds;
use Psr\Log\LoggerInterface;

beforeEach(function () {
    (function (): void {
        $this->namespace = 'LaravelNecromancer\\Tests\\Fixtures\\Bindings\\';
    })->call(app());

    $this->manifestPath = base_path('storage/framework/testing/necromancer-bindings.json');
});

afterEach(function () {
    File::delete($this->manifestPath);
});

/**
 * @return array<string, array<string, mixed>>
 */
function scanBindings(object $test, array $options = ['--only' => 'bindings']): array
{
    $test->artisan('necromancer:scan', ['--output' => $test->manifestPath, ...$options])->assertSuccessful();

    $bindings = json_decode(File::get($test->manifestPath), true, 512, JSON_THROW_ON_ERROR)['artifacts']['bindings'] ?? [];

    return array_column($bindings, null, 'id');
}

test('a class-string binding records its concrete class and a transient lifetime', function () {
    app()->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);

    $binding = scanBindings($this)['bindings:'.NecromancerPaymentGateway::class];

    expect($binding)->toMatchArray([
        'abstract' => NecromancerPaymentGateway::class,
        'concrete' => NecromancerStripeGateway::class,
        'concrete_source' => 'class',
        'lifetime' => 'transient',
        'provider' => null,
        'deferred' => false,
    ])->and($binding['source']['file'])->toEndWith('tests/Fixtures/Bindings/Services/NecromancerStripeGateway.php');
});

test('singleton, scoped, and instance registrations report their lifetime', function () {
    app()->singleton('necromancer.singleton', NecromancerStripeGateway::class);
    app()->scoped('necromancer.scoped', NecromancerStripeGateway::class);
    app()->instance(NecromancerLedger::class, new NecromancerLedger);

    $bindings = scanBindings($this);

    expect($bindings['bindings:necromancer.singleton'])->toMatchArray(['lifetime' => 'singleton', 'concrete' => NecromancerStripeGateway::class])
        ->and($bindings['bindings:necromancer.scoped'])->toMatchArray(['lifetime' => 'scoped', 'concrete' => NecromancerStripeGateway::class])
        ->and($bindings['bindings:'.NecromancerLedger::class])->toMatchArray([
            'concrete' => NecromancerLedger::class,
            'concrete_source' => 'instance',
            'lifetime' => 'instance',
        ]);
});

test('a singleton already resolved during boot still reports a singleton lifetime', function () {
    app()->singleton(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);
    app()->make(NecromancerPaymentGateway::class);

    expect(scanBindings($this)['bindings:'.NecromancerPaymentGateway::class])->toMatchArray([
        'concrete' => NecromancerStripeGateway::class,
        'concrete_source' => 'class',
        'lifetime' => 'singleton',
    ]);
});

test('a closure binding records its declared class return type, or no concrete without one', function () {
    app()->bind(NecromancerPaymentGateway::class, fn (): NecromancerStripeGateway => new NecromancerStripeGateway);
    app()->bind(NecromancerLedger::class, fn () => new NecromancerLedger);
    app()->bind('necromancer.untyped', fn (): string => 'value');

    $bindings = scanBindings($this);

    expect($bindings['bindings:'.NecromancerPaymentGateway::class])->toMatchArray([
        'concrete' => NecromancerStripeGateway::class,
        'concrete_source' => 'return_type',
    ])
        ->and($bindings['bindings:'.NecromancerLedger::class])->toMatchArray(['concrete' => null, 'concrete_source' => null])
        ->and($bindings['bindings:'.NecromancerLedger::class])->not->toHaveKey('source')
        ->and($bindings)->not->toHaveKey('bindings:necromancer.untyped');
});

test('the scan never calls a binding closure', function () {
    app()->bind(NecromancerPaymentGateway::class, function (): NecromancerStripeGateway {
        throw new RuntimeException('A binding closure was called during the scan.');
    });
    app()->singleton(NecromancerLedger::class, function (): NecromancerLedger {
        throw new RuntimeException('A singleton closure was called during the scan.');
    });

    $bindings = scanBindings($this);

    expect($bindings)->toHaveKeys(['bindings:'.NecromancerPaymentGateway::class, 'bindings:'.NecromancerLedger::class])
        ->and(app()->resolved(NecromancerPaymentGateway::class))->toBeFalse()
        ->and(app()->resolved(NecromancerLedger::class))->toBeFalse();
});

test('only bindings with an application abstract or concrete are collected', function () {
    app()->bind(LoggerInterface::class, NecromancerAuditLogger::class);
    app()->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);

    $bindings = scanBindings($this);

    expect(array_keys($bindings))->toBe([
        'bindings:'.NecromancerPaymentGateway::class,
        'bindings:'.LoggerInterface::class,
    ])->and($bindings['bindings:'.LoggerInterface::class]['concrete'])->toBe(NecromancerAuditLogger::class);
});

test('exclude.bindings removes bindings whose abstract matches a pattern', function () {
    config()->set('necromancer.exclude.bindings', ['*\\Contracts\\*']);
    app()->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);
    app()->bind(LoggerInterface::class, NecromancerAuditLogger::class);

    expect(array_keys(scanBindings($this)))->toBe(['bindings:'.LoggerInterface::class]);
});

test('a binding declared in a provider\'s $bindings or $singletons property is attributed to that provider', function () {
    app()->register(NecromancerPaymentServiceProvider::class);

    $bindings = scanBindings($this);

    expect($bindings['bindings:'.NecromancerPaymentGateway::class])->toMatchArray([
        'concrete' => NecromancerStripeGateway::class,
        'lifetime' => 'transient',
        'provider' => NecromancerPaymentServiceProvider::class,
    ])->and($bindings['bindings:'.NecromancerLedger::class])->toMatchArray([
        'concrete' => NecromancerLedger::class,
        'lifetime' => 'singleton',
        'provider' => NecromancerPaymentServiceProvider::class,
    ]);
});

test('a binding registered in a provider\'s register() code has no provider', function () {
    app()->register(NecromancerCodeServiceProvider::class);

    expect(scanBindings($this)['bindings:'.NecromancerPaymentGateway::class]['provider'])->toBeNull();
});

test('a binding a deferred application provider provides is marked deferred and attributed to it', function () {
    // Artisan loads every deferred provider before a command runs, so the
    // provider's register() has already bound its services by scan time.
    app()->addDeferredServices([
        NecromancerPaymentGateway::class => NecromancerDeferredServiceProvider::class,
        NecromancerRefundGateway::class => NecromancerDeferredServiceProvider::class,
    ]);

    $bindings = scanBindings($this);

    expect($bindings['bindings:'.NecromancerPaymentGateway::class])->toMatchArray([
        'concrete' => NecromancerStripeGateway::class,
        'concrete_source' => 'class',
        'lifetime' => 'singleton',
        'provider' => NecromancerDeferredServiceProvider::class,
        'deferred' => true,
    ])->and($bindings['bindings:'.NecromancerRefundGateway::class])->toMatchArray([
        'concrete' => NecromancerStripeRefunds::class,
        'lifetime' => 'transient',
        'provider' => NecromancerDeferredServiceProvider::class,
        'deferred' => true,
    ]);
});

test('bindings of non-deferred providers are not marked deferred', function () {
    app()->register(NecromancerPaymentServiceProvider::class);

    expect(scanBindings($this)['bindings:'.NecromancerPaymentGateway::class]['deferred'])->toBeFalse();
});

test('an interface an Action type-hints with a #[Bind] attribute matching the environment becomes an attribute binding', function () {
    app()->bind(ActionCollector::class, fn ($app): ActionCollector => new ActionCollector($app, [[
        'path' => base_path('tests/Fixtures/Bindings/Actions'),
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Bindings\\Actions\\',
    ]]));

    $bindings = scanBindings($this, ['--only' => 'actions,bindings']);

    expect(array_keys($bindings))->toBe(['bindings:'.NecromancerInvoiceNumbers::class])
        ->and($bindings['bindings:'.NecromancerInvoiceNumbers::class])->toMatchArray([
            'abstract' => NecromancerInvoiceNumbers::class,
            'concrete' => NecromancerFakeInvoiceNumbers::class,
            'concrete_source' => 'attribute',
            'lifetime' => 'singleton',
            'provider' => null,
            'deferred' => false,
        ])
        ->and(app()->bound(NecromancerInvoiceNumbers::class))->toBeFalse();
});

test('an exact-ID mapping annotates a binding', function () {
    config()->set('necromancer.annotations', [
        'bindings:'.NecromancerPaymentGateway::class => ['domain' => 'billing', 'external_services' => ['stripe']],
    ]);
    app()->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);

    expect(scanBindings($this)['bindings:'.NecromancerPaymentGateway::class]['annotations'])
        ->toBe(['domain' => 'billing', 'external_services' => ['stripe']]);
});

test('two consecutive scans of unchanged bindings produce the same content hash', function () {
    app()->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);
    app()->singleton(NecromancerLedger::class);

    $hashes = [];

    foreach ([1, 2] as $run) {
        $this->artisan('necromancer:scan', ['--output' => $this->manifestPath])->assertSuccessful();
        $hashes[] = json_decode(File::get($this->manifestPath), true, 512, JSON_THROW_ON_ERROR)['meta']['content_hash'];
    }

    expect($hashes[0])->toBe($hashes[1]);
});

test('outside the console kernel, an unloaded deferred provider\'s abstracts come from its declarations alone', function () {
    app()->addDeferredServices([
        NecromancerPaymentGateway::class => NecromancerUnloadedDeferredServiceProvider::class,
        NecromancerRefundGateway::class => NecromancerUnloadedDeferredServiceProvider::class,
    ]);

    $bindings = array_column(app(ScanManifest::class)->buildPayload(['bindings'])['artifacts']['bindings'], null, 'id');

    expect($bindings['bindings:'.NecromancerPaymentGateway::class])->toMatchArray([
        'concrete' => NecromancerStripeGateway::class,
        'concrete_source' => 'class',
        'lifetime' => 'singleton',
        'provider' => NecromancerUnloadedDeferredServiceProvider::class,
        'deferred' => true,
    ])->and($bindings['bindings:'.NecromancerRefundGateway::class])->toMatchArray([
        'concrete' => null,
        'concrete_source' => null,
        'lifetime' => null,
        'provider' => NecromancerUnloadedDeferredServiceProvider::class,
        'deferred' => true,
    ])->and(app()->providerIsLoaded(NecromancerUnloadedDeferredServiceProvider::class))->toBeFalse();
});

test('a contextual binding is recorded per consumer with its class-string concrete', function () {
    app()->when(NecromancerReportController::class)->needs(NecromancerPaymentGateway::class)->give(NecromancerFakePaymentGateway::class);

    $binding = scanBindings($this)['bindings:'.NecromancerPaymentGateway::class.'@'.NecromancerReportController::class];

    expect($binding)->toMatchArray([
        'abstract' => NecromancerPaymentGateway::class,
        'concrete' => NecromancerFakePaymentGateway::class,
        'concrete_source' => 'class',
        'lifetime' => null,
        'provider' => null,
        'deferred' => false,
        'consumer' => NecromancerReportController::class,
    ])->and($binding['source']['file'])->toEndWith('tests/Fixtures/Bindings/Services/NecromancerFakePaymentGateway.php');
});

test('a contextual closure records its declared class return type, or no concrete without one', function () {
    app()->when(NecromancerReportController::class)->needs(NecromancerPaymentGateway::class)->give(fn (): NecromancerFakePaymentGateway => new NecromancerFakePaymentGateway);
    app()->when(NecromancerRefundController::class)->needs(NecromancerPaymentGateway::class)->give(fn () => new NecromancerFakePaymentGateway);

    $bindings = scanBindings($this);

    expect($bindings['bindings:'.NecromancerPaymentGateway::class.'@'.NecromancerReportController::class])->toMatchArray([
        'concrete' => NecromancerFakePaymentGateway::class,
        'concrete_source' => 'return_type',
    ])->and($bindings['bindings:'.NecromancerPaymentGateway::class.'@'.NecromancerRefundController::class])->toMatchArray([
        'concrete' => null,
        'concrete_source' => null,
    ]);
});

test('a primitive need is never collected and its value never reaches the manifest', function () {
    app()->when(NecromancerReportController::class)->needs('$apiKey')->give('sk_live_never_in_the_manifest');
    app()->when(NecromancerReportController::class)->needs('$timeout')->give(fn (): NecromancerFakePaymentGateway => throw new RuntimeException('A primitive need was read.'));

    $this->artisan('necromancer:scan', ['--output' => $this->manifestPath, '--only' => 'bindings'])->assertSuccessful();

    expect(File::get($this->manifestPath))->not->toContain('sk_live_never_in_the_manifest')
        ->not->toContain('$apiKey')
        ->not->toContain('$timeout');
});

test('a contextual binding for several consumers yields one artifact per consumer', function () {
    app()->when([NecromancerReportController::class, NecromancerRefundController::class])->needs(NecromancerPaymentGateway::class)->give(NecromancerFakePaymentGateway::class);

    expect(array_keys(scanBindings($this)))->toBe([
        'bindings:'.NecromancerPaymentGateway::class.'@'.NecromancerRefundController::class,
        'bindings:'.NecromancerPaymentGateway::class.'@'.NecromancerReportController::class,
    ]);
});

test('a global binding has no consumer key and sorts before its contextual bindings', function () {
    app()->bind(NecromancerPaymentGateway::class, NecromancerStripeGateway::class);
    app()->when(NecromancerReportController::class)->needs(NecromancerPaymentGateway::class)->give(NecromancerFakePaymentGateway::class);

    $bindings = scanBindings($this);

    expect(array_keys($bindings))->toBe([
        'bindings:'.NecromancerPaymentGateway::class,
        'bindings:'.NecromancerPaymentGateway::class.'@'.NecromancerReportController::class,
    ])->and($bindings['bindings:'.NecromancerPaymentGateway::class])->not->toHaveKey('consumer');
});

test('a contextual binding is kept when only its consumer is in the application namespace', function () {
    app()->when(NecromancerReportController::class)->needs(Countable::class)->give(ArrayObject::class);
    app()->when(ArrayObject::class)->needs(Countable::class)->give(ArrayIterator::class);

    expect(array_keys(scanBindings($this)))->toBe([
        'bindings:'.Countable::class.'@'.NecromancerReportController::class,
    ]);
});

test('exclude.bindings drops a contextual binding whose abstract matches', function () {
    config()->set('necromancer.exclude.bindings', ['*\\Contracts\\*']);
    app()->when(NecromancerReportController::class)->needs(NecromancerPaymentGateway::class)->give(NecromancerFakePaymentGateway::class);

    expect(scanBindings($this))->toBe([]);
});

test('a contextual binding of an abstract does not hide its #[Bind] global binding', function () {
    app()->bind(ActionCollector::class, fn ($app): ActionCollector => new ActionCollector($app, [[
        'path' => base_path('tests/Fixtures/Bindings/Actions'),
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Bindings\\Actions\\',
    ]]));
    app()->when(NecromancerReportController::class)->needs(NecromancerInvoiceNumbers::class)->give(NecromancerSequentialInvoiceNumbers::class);

    expect(array_keys(scanBindings($this, ['--only' => 'actions,bindings'])))->toBe([
        'bindings:'.NecromancerInvoiceNumbers::class,
        'bindings:'.NecromancerInvoiceNumbers::class.'@'.NecromancerReportController::class,
    ]);
});
