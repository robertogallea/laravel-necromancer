<?php

use App\Providers\NecromancerFixtureServiceProvider;
use Illuminate\Auth\Access\Gate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use LaravelNecromancer\Collection\CommandCollector;
use LaravelNecromancer\Collection\EnumCollector;
use LaravelNecromancer\Collection\EventCollector;
use LaravelNecromancer\Collection\FormRequestCollector;
use LaravelNecromancer\Collection\JobCollector;
use LaravelNecromancer\Collection\ListenerCollector;
use LaravelNecromancer\Collection\LivewireCollector;
use LaravelNecromancer\Collection\MailableCollector;
use LaravelNecromancer\Collection\ModelCollector;
use LaravelNecromancer\Collection\ObserverCollector;
use LaravelNecromancer\Collection\PolicyCollector;
use LaravelNecromancer\Collection\RuleCollector;
use LaravelNecromancer\Collection\TestCollector;
use LaravelNecromancer\Commands\ScanCommand;
use LaravelNecromancer\Tests\Fixtures\Commands\NecromancerFixtureCommand;
use LaravelNecromancer\Tests\Fixtures\Enums\NecromancerPriority;
use LaravelNecromancer\Tests\Fixtures\Enums\NecromancerStatus;
use LaravelNecromancer\Tests\Fixtures\Events\NecromancerBroadcastedEvent;
use LaravelNecromancer\Tests\Fixtures\Events\NecromancerOrderPlaced;
use LaravelNecromancer\Tests\Fixtures\Gates\NecromancerManageUsersGate;
use LaravelNecromancer\Tests\Fixtures\InvalidJobs\NecromancerInvalidAnnotatedJob;
use LaravelNecromancer\Tests\Fixtures\Jobs\NecromancerAnnotatedJob;
use LaravelNecromancer\Tests\Fixtures\Jobs\NecromancerQueuedJob;
use LaravelNecromancer\Tests\Fixtures\Listeners\RecordNecromancerOrderMetrics;
use LaravelNecromancer\Tests\Fixtures\Listeners\SendNecromancerReceipt;
use LaravelNecromancer\Tests\Fixtures\Livewire\NecromancerIssueForm;
use LaravelNecromancer\Tests\Fixtures\Mail\NecromancerPasswordResetMail;
use LaravelNecromancer\Tests\Fixtures\Mail\NecromancerWelcomeMail;
use LaravelNecromancer\Tests\Fixtures\Middleware\NecromancerAnnotatedMiddleware;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerCustomer;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerMember;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerOrder;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerReport;
use LaravelNecromancer\Tests\Fixtures\Models\NecromancerUnguardedModel;
use LaravelNecromancer\Tests\Fixtures\NecromancerAnnotatedInvokableRouteController;
use LaravelNecromancer\Tests\Fixtures\NecromancerAnnotatedRouteController;
use LaravelNecromancer\Tests\Fixtures\NecromancerFakeMetadataRoute;
use LaravelNecromancer\Tests\Fixtures\NecromancerInvokableRouteController;
use LaravelNecromancer\Tests\Fixtures\NecromancerRouteController;
use LaravelNecromancer\Tests\Fixtures\Observers\NecromancerIssueObserver;
use LaravelNecromancer\Tests\Fixtures\Policies\NecromancerPostPolicy;
use LaravelNecromancer\Tests\Fixtures\Requests\NecromancerStoreOrderRequest;
use LaravelNecromancer\Tests\Fixtures\Rules\NecromancerRequiredIfMemberRule;
use LaravelNecromancer\Tests\Fixtures\Rules\NecromancerUniqueInProjectRule;
use PHPUnit\Framework\Assert;

beforeEach(function () {
    cleanNecromancerScanTestFiles();
});

afterEach(function () {
    cleanNecromancerScanTestFiles();
});

test('the scan command writes a minimal manifest to the default path', function () {
    $path = base_path('necromancer.json');

    $this->artisan('necromancer:scan')
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    expect(File::exists($path))->toBeTrue();

    expectMinimalScanManifest($path);
});

test('the scan command records complete and partial scan scope', function () {
    $fullPath = necromancerScanTestPath('necromancer-full-scope.json');
    $partialPath = necromancerScanTestPath('necromancer-partial-scope.json');

    $this->artisan('necromancer:scan', ['--output' => $fullPath])->assertSuccessful();
    $this->artisan('necromancer:scan', ['--output' => $partialPath, '--only' => 'routes'])->assertSuccessful();

    $full = expectScanManifest($fullPath);
    $partial = expectScanManifest($partialPath);

    expect($full->meta->scope->complete)->toBeTrue()
        ->and($full->meta->scope->artifact_types)->toBe([
            'commands', 'enums', 'events', 'form_requests', 'gates', 'jobs', 'listeners',
            'livewire_components', 'mailables', 'middleware', 'models', 'observers',
            'policies', 'routes', 'scheduled_tasks', 'service_providers', 'tests', 'validation_rules',
        ])
        ->and($partial->meta->scope->complete)->toBeFalse()
        ->and($partial->meta->scope->artifact_types)->toBe(['routes']);
});

test('the scan command writes named and unnamed closure route artifacts', function () {
    $path = necromancerScanTestPath('necromancer-routes-closure.json');
    $secret = 'necromancer-route-closure-secret';

    config(['services.necromancer.secret' => $secret]);

    Route::middleware('web')->get('/necromancer/named-route', function () use ($secret) {
        return $secret;
    })->name('necromancer.named-route');

    Route::post('/necromancer/unnamed-route', function () {
        return 'unnamed route';
    });

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $namedRoute = findManifestRouteByName($manifest, 'necromancer.named-route');
    $unnamedRoute = findManifestRouteByUri($manifest, 'necromancer/unnamed-route');

    expect($namedRoute->method)->toBe('GET')
        ->and($namedRoute->uri)->toBe('necromancer/named-route')
        ->and($namedRoute->middleware)->toBe(['web'])
        ->and($namedRoute->controller)->toBeNull()
        ->and($namedRoute->action)->toBe('Closure')
        ->and($namedRoute)->toHaveProperty('source')
        ->and($namedRoute->source->file)->toBe('tests/Feature/NecromancerScanCommandTest.php')
        ->and($namedRoute->source->line)->toBeInt()
        ->and($unnamedRoute->name)->toBeNull()
        ->and($unnamedRoute->method)->toBe('POST')
        ->and((string) File::get($path))->not->toContain($secret);
});

test('the scan command projects native route metadata into canonical annotations', function () {
    $path = necromancerScanTestPath('necromancer-route-annotations.json');
    $route = new NecromancerFakeMetadataRoute(['POST'], '/necromancer/subscriptions/cancel', ['uses' => fn () => 'ok']);
    $route->name('necromancer.subscription.cancel');
    $route->metadata([
        'head' => ['title' => 'Cancel subscription'],
        'necromancer' => [
            'domain' => ' billing ',
            'flow' => 'subscription-cancellation',
            'capability' => 'subscription.cancel',
            'summary' => 'Cancels an active subscription.',
            'risk' => 'high',
            'external_services' => ['stripe', 'stripe'],
            'adr' => 'docs/adr/001.md',
            'adrs' => ['docs/adr/002.md', 'docs/adr/001.md'],
        ],
    ]);
    app(Router::class)->getRoutes()->add($route);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'routes'])->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.subscription.cancel');

    expect($route->route_metadata->raw->head->title)->toBe('Cancel subscription')
        ->and($route->route_metadata->raw->necromancer->domain)->toBe(' billing ')
        ->and($route->route_metadata)->not->toHaveProperty('necromancer')
        ->and($route->annotations)->toEqual((object) [
            'domain' => 'billing',
            'flow' => 'subscription-cancellation',
            'capability' => 'subscription.cancel',
            'summary' => 'Cancels an active subscription.',
            'risk' => 'high',
            'external_services' => ['stripe'],
            'adrs' => ['docs/adr/001.md', 'docs/adr/002.md'],
        ]);
});

test('the scan command warns about native route metadata values that cannot enter Annotation Schema v1', function () {
    $path = necromancerScanTestPath('necromancer-invalid-route-annotations.json');
    $route = new NecromancerFakeMetadataRoute(['POST'], '/necromancer/schema-invalid', ['uses' => fn () => 'ok']);
    $route->name('necromancer.schema.invalid');
    $route->metadata(['necromancer' => [
        'domain' => 'billing',
        'risk' => 'urgent',
        'external_services' => ['stripe', ''],
    ]]);
    app(Router::class)->getRoutes()->add($route);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'routes'])
        ->expectsOutputToContain('AN_SCHEMA_INCOMPATIBLE_RISK')
        ->expectsOutputToContain('AN_SCHEMA_INCOMPATIBLE_VALUE')
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.schema.invalid');

    expect($route->route_metadata->raw->necromancer->risk)->toBe('urgent')
        ->and($route->route_metadata)->not->toHaveProperty('necromancer')
        ->and($route->annotations)->toEqual((object) [
            'domain' => 'billing',
            'external_services' => ['stripe'],
        ]);
});

test('the scan command joins multiple non head methods in registered order', function () {
    $path = necromancerScanTestPath('necromancer-routes-methods.json');

    Route::match(['GET', 'POST'], '/necromancer/multi-method', function () {
        return 'multi method route';
    })->name('necromancer.multi-method');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.multi-method');

    expect($route->method)->toBe('GET|POST');
});

test('the scan command writes controller route action metadata and source', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller.json');

    Route::get('/necromancer/controller-route', [NecromancerRouteController::class, 'show'])
        ->name('necromancer.controller-route');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.controller-route');

    expect($route->method)->toBe('GET')
        ->and($route->uri)->toBe('necromancer/controller-route')
        ->and($route->controller)->toBe(NecromancerRouteController::class)
        ->and($route->action)->toBe('show')
        ->and($route->source->file)->toBe('tests/Fixtures/NecromancerRouteController.php')
        ->and($route->source->line)->toBeInt();
});

test('the scan command writes invokable controller route action metadata and source', function () {
    $path = necromancerScanTestPath('necromancer-routes-invokable.json');

    Route::post('/necromancer/invokable-route', NecromancerInvokableRouteController::class)
        ->name('necromancer.invokable-route');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.invokable-route');

    expect($route->method)->toBe('POST')
        ->and($route->uri)->toBe('necromancer/invokable-route')
        ->and($route->controller)->toBe(NecromancerInvokableRouteController::class)
        ->and($route->action)->toBe('__invoke')
        ->and($route->source->file)->toBe('tests/Fixtures/NecromancerInvokableRouteController.php')
        ->and($route->source->line)->toBeInt();
});

test('an invokable controller resolves its #[Necromancer] attribute from __invoke as the action-level source', function () {
    $path = necromancerScanTestPath('necromancer-routes-invokable-annotated.json');

    Route::post('/necromancer/invokable-annotated-route', NecromancerAnnotatedInvokableRouteController::class)
        ->name('necromancer.invokable-annotated-route');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.invokable-annotated-route');

    expect($route->annotations)->toEqual((object) ['domain' => 'billing', 'capability' => 'billing.process']);
});

test('a controller class annotation supplies defaults for an unannotated action', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller-class-annotation.json');

    Route::get('/necromancer/annotated/index', [NecromancerAnnotatedRouteController::class, 'index'])
        ->name('necromancer.annotated.index');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.annotated.index');

    expect($route->annotations)->toEqual((object) ['domain' => 'billing', 'risk' => 'low']);
});

test('an action annotation refines the controller class defaults without a warning', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller-action-annotation.json');

    Route::get('/necromancer/annotated/charge', [NecromancerAnnotatedRouteController::class, 'charge'])
        ->name('necromancer.annotated.charge');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.annotated.charge');

    expect($route->annotations)->toEqual((object) [
        'domain' => 'billing',
        'capability' => 'billing.charge',
        'risk' => 'low',
    ]);
});

test('an action annotation deterministically overrides a conflicting controller class value with no warning', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller-conflict-annotation.json');

    Route::get('/necromancer/annotated/conflict', [NecromancerAnnotatedRouteController::class, 'conflictingDomain'])
        ->name('necromancer.annotated.conflict');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->doesntExpectOutputToContain('AN_SOURCE_CONFLICT')
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.annotated.conflict');

    expect($route->annotations)->toEqual((object) ['domain' => 'support', 'risk' => 'low']);
});

test('native route metadata overrides controller-derived annotations with a warning', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller-metadata-conflict.json');

    Route::get('/necromancer/annotated/native-override', [NecromancerAnnotatedRouteController::class, 'index'])
        ->name('necromancer.annotated.native-override')
        ->withNecromancer(domain: 'enterprise');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain('AN_SOURCE_CONFLICT')
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.annotated.native-override');

    expect($route->annotations)->toEqual((object) ['domain' => 'enterprise', 'risk' => 'low']);
});

test('the conflict warning names the route by its canonical routes:METHOD:URI Artifact ID', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller-metadata-conflict-id.json');

    Route::get('/necromancer/annotated/canonical-id-override', [NecromancerAnnotatedRouteController::class, 'index'])
        ->name('necromancer.annotated.canonical-id-override')
        ->withNecromancer(domain: 'enterprise');

    Artisan::call('necromancer:scan', ['--output' => $path]);

    expect(Artisan::output())->toContain('routes:GET:necromancer/annotated/canonical-id-override');
});

test('two routes conflicting on the same field each surface their own warning instead of collapsing into one', function () {
    $path = necromancerScanTestPath('necromancer-routes-controller-metadata-conflict-distinct.json');

    Route::get('/necromancer/annotated/native-override-a', [NecromancerAnnotatedRouteController::class, 'index'])
        ->name('necromancer.annotated.native-override-a')
        ->withNecromancer(domain: 'enterprise');

    Route::get('/necromancer/annotated/native-override-b', [NecromancerAnnotatedRouteController::class, 'index'])
        ->name('necromancer.annotated.native-override-b')
        ->withNecromancer(domain: 'partner');

    // Each conflict warning names its own route's method+URI, so the two warnings
    // are distinct strings and neither collapses the other via array_unique().
    Artisan::call('necromancer:scan', ['--output' => $path]);
    $warnings = array_values(array_filter(
        explode("\n", Artisan::output()),
        fn (string $line): bool => str_contains($line, 'AN_SOURCE_CONFLICT') && str_contains($line, 'domain'),
    ));

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->not->toBe($warnings[1])
        ->and($warnings[0])->toContain('native-override-a')
        ->and($warnings[1])->toContain('native-override-b');
});

test('the scan command excludes default vendor and debug route names', function (string $routeName) {
    $path = necromancerScanTestPath('necromancer-routes-default-exclusions.json');

    Route::get('/necromancer/vendor-route', function () {
        return 'vendor route';
    })->name($routeName);

    Route::get('/necromancer/application-route', function () {
        return 'application route';
    })->name('necromancer.application-route');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    expect(manifestRouteNames($manifest))
        ->not->toContain($routeName)
        ->toContain('necromancer.application-route');
})->with([
    'horizon' => 'horizon.dashboard',
    'telescope' => 'telescope.requests',
    'debugbar' => 'debugbar.assets.css',
]);

test('the scan command respects configured route exclusions', function () {
    $path = necromancerScanTestPath('necromancer-routes-configured-exclusions.json');

    config([
        'necromancer.exclude.routes' => [
            ...config('necromancer.exclude.routes'),
            'internal.*',
        ],
    ]);

    Route::get('/necromancer/internal-route', function () {
        return 'internal route';
    })->name('internal.health');

    Route::get('/necromancer/public-route', function () {
        return 'public route';
    })->name('necromancer.public-route');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    expect(manifestRouteNames($manifest))
        ->not->toContain('internal.health')
        ->toContain('necromancer.public-route');
});

test('the scan command excludes default Livewire and Inertia DevTools routes by URI', function () {
    $path = necromancerScanTestPath('necromancer-routes-default-uri-exclusions.json');

    Route::get('/livewire-b02e7ba0/livewire.min.js.map', function () {
        return 'livewire source map';
    });

    Route::get('/_inertia/devtools/entries', function () {
        return 'inertia devtools entries';
    });

    Route::get('/necromancer/unnamed-application-route', function () {
        return 'unnamed application route';
    });

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    expect(manifestRouteUris($manifest))
        ->not->toContain('livewire-b02e7ba0/livewire.min.js.map')
        ->not->toContain('_inertia/devtools/entries')
        ->toContain('necromancer/unnamed-application-route');
});

test('the scan command captures route parameters with optionality and constraints', function () {
    $path = necromancerScanTestPath('necromancer-routes-parameters.json');

    Route::get('/necromancer/items/{id}/{slug?}', function () {
        return 'items route';
    })->name('necromancer.items')->where('id', '\d+');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $route = findManifestRouteByName(expectScanManifest($path), 'necromancer.items');

    expect($route->parameters)->toHaveCount(2)
        ->and($route->parameters[0]->name)->toBe('id')
        ->and($route->parameters[0]->optional)->toBeFalse()
        ->and($route->parameters[0]->constraint)->toBe('\d+')
        ->and($route->parameters[1]->name)->toBe('slug')
        ->and($route->parameters[1]->optional)->toBeTrue()
        ->and($route->parameters[1]->constraint)->toBeNull();
});

test('the scan command writes model artifacts with safe structural metadata', function () {
    $path = necromancerScanTestPath('necromancer-models-basic.json');
    $secret = 'necromancer-model-secret-value';

    useNecromancerFixtureModels();
    config(['services.necromancer.model_secret' => $secret]);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $customer = findManifestModel($manifest, NecromancerCustomer::class);

    expect($customer->table)->toBe('necromancer_customers')
        ->and($customer->fillable)->toBe(['name', 'email'])
        ->and((array) $customer->casts)->toMatchArray([
            'id' => 'int',
            'is_active' => 'boolean',
            'onboarded_at' => 'datetime',
        ])
        ->and($customer->source->file)->toBe('tests/Fixtures/Models/NecromancerCustomer.php')
        ->and($customer->source->line)->toBeInt()
        ->and((string) File::get($path))->not->toContain($secret);
});

test('the scan command writes model relationships and skips unsafe methods', function () {
    $path = necromancerScanTestPath('necromancer-models-relationships.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $customer = findManifestModel($manifest, NecromancerCustomer::class);
    $order = findManifestModel($manifest, NecromancerOrder::class);

    expect($customer->relationships)->toHaveCount(1)
        ->and($customer->relationships[0])->toMatchObject([
            'type' => 'hasMany',
            'related' => NecromancerOrder::class,
            'method' => 'orders',
        ])
        ->and($order->relationships)->toHaveCount(1)
        ->and($order->relationships[0])->toMatchObject([
            'type' => 'belongsTo',
            'related' => NecromancerCustomer::class,
            'method' => 'customer',
        ]);
});

test('the scan command does not collect abstract or non model classes', function () {
    $path = necromancerScanTestPath('necromancer-models-skips-non-models.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    expect(manifestModelClasses(expectScanManifest($path)))
        ->toContain(NecromancerCustomer::class)
        ->not->toContain(NecromancerReport::class)
        ->not->toContain('LaravelNecromancer\\Tests\\Fixtures\\Models\\AbstractNecromancerModel');
});

test('the scan command respects configured model exclusions', function () {
    $path = necromancerScanTestPath('necromancer-models-exclusions.json');

    useNecromancerFixtureModels();
    config([
        'necromancer.exclude.models' => [
            NecromancerOrder::class,
            'LaravelNecromancer\\Tests\\Fixtures\\Models\\NecromancerCust*',
        ],
    ]);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    expect(manifestModelClasses(expectScanManifest($path)))
        ->not->toContain(NecromancerCustomer::class)
        ->not->toContain(NecromancerOrder::class);
});

test('the scan command captures hidden fields, soft deletes, and local scopes on models', function () {
    $path = necromancerScanTestPath('necromancer-models-extended.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $member = findManifestModel(expectScanManifest($path), NecromancerMember::class);

    expect($member->hidden)->toBe(['password', 'remember_token'])
        ->and($member->appends)->toBe(['display_name'])
        ->and($member->soft_deletes)->toBeTrue()
        ->and($member->scopes)->toBe(['active', 'verified']);
});

test('the scan command writes empty hidden and scopes as absent keys on models without them', function () {
    $path = necromancerScanTestPath('necromancer-models-no-hidden.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $order = findManifestModel(expectScanManifest($path), NecromancerOrder::class);

    expect(isset($order->hidden))->toBeFalse()
        ->and(isset($order->appends))->toBeFalse()
        ->and($order->soft_deletes)->toBeFalse()
        ->and(isset($order->scopes))->toBeFalse();
});

test('the scan command writes policy artifacts with methods and guarded model', function () {
    $path = necromancerScanTestPath('necromancer-policies-basic.json');

    app()->bind(
        PolicyCollector::class,
        fn ($app): PolicyCollector => new PolicyCollector($app, [[
            'path' => base_path('tests/Fixtures/Policies'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Policies\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $policy = findManifestPolicy($manifest, NecromancerPostPolicy::class);

    expect($policy->model)->toBe(NecromancerOrder::class)
        ->and($policy->methods)->toBe(['create', 'delete', 'update', 'viewAny'])
        ->and($policy->source->file)->toBe('tests/Fixtures/Policies/NecromancerPostPolicy.php')
        ->and($policy->source->line)->toBeInt();
});

test('the --only=policies scan restricts to policy artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-policies.json');

    app()->bind(
        PolicyCollector::class,
        fn ($app): PolicyCollector => new PolicyCollector($app, [[
            'path' => base_path('tests/Fixtures/Policies'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Policies\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'policies'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['policies']);
});

test('the scan command writes observer artifacts with correct hooks, queued=false, and source', function () {
    $path = necromancerScanTestPath('necromancer-observers-basic.json');

    app()->bind(
        ObserverCollector::class,
        fn ($app): ObserverCollector => new ObserverCollector($app, roots: [[
            'path' => base_path('tests/Fixtures/Observers'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Observers\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $observer = findManifestObserver($manifest, NecromancerIssueObserver::class);

    // No model fixture has #[ObservedBy] pointing at NecromancerIssueObserver, so
    // the reverse-lookup map is empty and model must be null.
    expect($observer->hooks)->toContain('created')
        ->and($observer->hooks)->toContain('deleted')
        ->and($observer->hooks)->toContain('updating')
        ->and($observer->queued)->toBeFalse()
        ->and($observer->model)->toBeNull()
        ->and($observer->source->file)->toBe('tests/Fixtures/Observers/NecromancerIssueObserver.php');
});

test('the --only=observers scan restricts to observer artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-observers.json');

    app()->bind(
        ObserverCollector::class,
        fn ($app): ObserverCollector => new ObserverCollector($app, roots: [[
            'path' => base_path('tests/Fixtures/Observers'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Observers\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'observers'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['observers']);
});

test('the scan command writes enum artifacts with backing type and cases', function () {
    $path = necromancerScanTestPath('necromancer-enums-backed.json');

    app()->bind(
        EnumCollector::class,
        fn ($app): EnumCollector => new EnumCollector($app, [[
            'path' => base_path('tests/Fixtures/Enums'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Enums\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $status = findManifestEnum($manifest, NecromancerStatus::class);
    $priority = findManifestEnum($manifest, NecromancerPriority::class);

    expect($status->backing_type)->toBe('string')
        ->and(array_column((array) $status->cases, 'name'))->toContain('Active')
        ->and(array_column((array) $status->cases, 'value'))->toContain('active')
        ->and($status->source->file)->toBe('tests/Fixtures/Enums/NecromancerStatus.php')
        ->and($priority->backing_type)->toBeNull()
        ->and(array_column((array) $priority->cases, 'name'))->toContain('Low')
        ->and(array_column((array) $priority->cases, 'value'))->each->toBeNull();
});

test('the --only=enums scan restricts to enum artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-enums.json');

    app()->bind(
        EnumCollector::class,
        fn ($app): EnumCollector => new EnumCollector($app, [[
            'path' => base_path('tests/Fixtures/Enums'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Enums\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'enums'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['enums']);
});

test('the scan command writes test artifacts with file, type, and methods', function () {
    $path = necromancerScanTestPath('necromancer-tests-basic.json');

    useNecromancerFixtureTests();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $functional = findManifestTestByFilename($manifest, 'NecromancerFunctionalTest.php');

    expect($functional->type)->toBe('unit')
        ->and(isset($functional->class))->toBeFalse()
        ->and($functional->methods)->toContain('it creates an order', 'calculates the total');
});

test('the --only=tests scan restricts to test artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-tests.json');

    useNecromancerFixtureTests();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'tests'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['tests']);
});

test('the scan command captures the subject for a uses() fixture test', function () {
    $path = necromancerScanTestPath('necromancer-tests-uses.json');

    useNecromancerFixtureTests();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $usesTest = findManifestTestByFilename($manifest, 'NecromancerUsesTest.php');

    expect($usesTest->subject)->toBe('LaravelNecromancer\\Tests\\Fixtures\\Models\\NecromancerOrder');
});

test('the scan command captures class-based test artifacts with the class FQCN', function () {
    $path = necromancerScanTestPath('necromancer-tests-class-based.json');

    useNecromancerFixtureTests();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $classBased = findManifestTestByFilename($manifest, 'NecromancerClassBasedTest.php');

    expect($classBased->class)->toBe('LaravelNecromancer\\Tests\\Fixtures\\Tests\\NecromancerClassBasedTest')
        ->and($classBased->methods)->toContain('test_it_creates_an_order', 'test_it_calculates_total');
});

test('the scan command captures guarded on model artifacts', function () {
    $path = necromancerScanTestPath('necromancer-models-guarded.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $member = findManifestModel(expectScanManifest($path), NecromancerMember::class);
    $unguarded = findManifestModel(expectScanManifest($path), NecromancerUnguardedModel::class);

    expect($member->guarded)->toBe(['*'])
        ->and($unguarded->guarded)->toBe([]);
});

test('the scan command writes job artifacts with safe structural metadata', function () {
    $path = necromancerScanTestPath('necromancer-jobs-basic.json');
    $secret = 'necromancer-job-secret-value';

    useNecromancerFixtureExecutables();
    config(['services.necromancer.job_secret' => $secret]);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $job = findManifestJob(expectScanManifest($path), NecromancerQueuedJob::class);

    expect($job->queue)->toBe('necromancer-jobs')
        ->and($job->connection)->toBe('redis')
        ->and($job->tries)->toBe(3)
        ->and($job->source->file)->toBe('tests/Fixtures/Jobs/NecromancerQueuedJob.php')
        ->and($job->source->line)->toBeInt()
        ->and((string) File::get($path))->not->toContain($secret);
});

test('the scan command captures timeout on job artifacts', function () {
    $path = necromancerScanTestPath('necromancer-jobs-timeout.json');

    useNecromancerFixtureExecutables();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $job = findManifestJob(expectScanManifest($path), NecromancerQueuedJob::class);

    expect($job->timeout)->toBeNull();
});

test('a #[Necromancer] class attribute serializes into a class-backed artifact', function () {
    $path = necromancerScanTestPath('necromancer-jobs-annotated.json');

    useNecromancerFixtureExecutables();

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();

    $job = findManifestJob(expectScanManifest($path), NecromancerAnnotatedJob::class);

    expect($job->annotations)->toEqual((object) [
        'domain' => 'billing',
        'capability' => 'invoice.send',
        'risk' => 'high',
        'external_services' => ['stripe'],
    ]);
});

test('the scan command captures broadcastable and channels on event artifacts', function () {
    $path = necromancerScanTestPath('necromancer-events-broadcastable.json');

    app()->bind(
        EventCollector::class,
        fn ($app): EventCollector => new EventCollector($app, [[
            'path' => base_path('tests/Fixtures/Events'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Events\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $regular = findManifestEvent($manifest, NecromancerOrderPlaced::class);
    $broadcasted = findManifestEvent($manifest, NecromancerBroadcastedEvent::class);

    expect($regular->broadcastable)->toBeFalse()
        ->and(isset($regular->channels))->toBeFalse()
        ->and($broadcasted->broadcastable)->toBeTrue()
        ->and($broadcasted->channels)->toBe([]);
});

test('the scan command writes form request artifacts with rules and source', function () {
    $path = necromancerScanTestPath('necromancer-form-requests-basic.json');

    useNecromancerFixtureRequests();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $request = findManifestFormRequest(expectScanManifest($path), NecromancerStoreOrderRequest::class);

    expect((array) $request->rules)->toBe([
        'customer_id' => 'required|integer',
        'total' => 'required|numeric',
    ])
        ->and($request->source->file)->toBe('tests/Fixtures/Requests/NecromancerStoreOrderRequest.php')
        ->and($request->source->line)->toBeInt();
});

test('the --only=form_requests scan restricts to form request artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-form-requests.json');

    useNecromancerFixtureRequests();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'form_requests'])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    expect(array_keys((array) $manifest->artifacts))->toBe(['form_requests']);

    findManifestFormRequest($manifest, NecromancerStoreOrderRequest::class);
});

test('the scan command writes event and listener message-flow artifacts', function () {
    $path = necromancerScanTestPath('necromancer-events-listeners.json');

    useNecromancerFixtureExecutables();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $event = findManifestEvent($manifest, NecromancerOrderPlaced::class);
    $queuedListener = findManifestListener($manifest, SendNecromancerReceipt::class);
    $invokableListener = findManifestListener($manifest, RecordNecromancerOrderMetrics::class);

    expect($event->listeners)
        ->toHaveCount(2)
        ->toContain(SendNecromancerReceipt::class)
        ->toContain(RecordNecromancerOrderMetrics::class)
        ->and($event->source->file)->toBe('tests/Fixtures/Events/NecromancerOrderPlaced.php')
        ->and($event->source->line)->toBeInt()
        ->and($queuedListener->handles)->toBe([NecromancerOrderPlaced::class])
        ->and($queuedListener->queued)->toBeTrue()
        ->and($queuedListener->source->file)->toBe('tests/Fixtures/Listeners/SendNecromancerReceipt.php')
        ->and($queuedListener->source->line)->toBeInt()
        ->and($invokableListener->handles)->toBe([NecromancerOrderPlaced::class])
        ->and($invokableListener->queued)->toBeFalse()
        ->and($invokableListener->source->file)->toBe('tests/Fixtures/Listeners/RecordNecromancerOrderMetrics.php')
        ->and($invokableListener->source->line)->toBeInt();
});

test('the scan command writes only application local class based artisan command artifacts', function () {
    $path = necromancerScanTestPath('necromancer-commands-basic.json');

    useNecromancerFixtureExecutables();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $command = findManifestCommand($manifest, NecromancerFixtureCommand::class);

    expect($command->signature)->toBe('necromancer:fixture {--force}')
        ->and($command->description)->toBe('Fixture command for Necromancer scans')
        ->and($command->source->file)->toBe('tests/Fixtures/Commands/NecromancerFixtureCommand.php')
        ->and($command->source->line)->toBeInt()
        ->and(manifestCommandClasses($manifest))->not->toContain(ScanCommand::class)
        ->and(manifestCommandSignatures($manifest))->not->toContain('inspire');
});

test('the scan command writes to the configured manifest path', function () {
    $path = necromancerScanTestPath('necromancer-configured-output.json');

    config(['necromancer.output.manifest' => $path]);

    $this->artisan('necromancer:scan')
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    expectMinimalScanManifest($path);
});

test('the scan command resolves relative output paths from the application base path', function () {
    $relativePath = 'storage/framework/testing/necromancer-relative-output.json';
    $path = base_path($relativePath);

    $this->artisan('necromancer:scan', ['--output' => $relativePath])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    expectMinimalScanManifest($path);
});

test('the scan command writes to absolute output paths', function () {
    $path = necromancerScanTestPath('necromancer-absolute-output.json');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain($path)
        ->assertSuccessful();

    expectMinimalScanManifest($path);
});

test('the output option takes precedence over the configured manifest path', function () {
    $configuredPath = necromancerScanTestPath('necromancer-configured-precedence.json');
    $overridePath = necromancerScanTestPath('necromancer-option-precedence.json');

    config(['necromancer.output.manifest' => $configuredPath]);

    $this->artisan('necromancer:scan', ['--output' => $overridePath])
        ->expectsOutputToContain($overridePath)
        ->assertSuccessful();

    expect(File::exists($configuredPath))->toBeFalse();
    expectMinimalScanManifest($overridePath);
});

test('the scan command fails clearly when the output parent directory is missing', function () {
    $path = storage_path('framework/testing/missing-necromancer-output/manifest.json');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain("Unable to write Necromancer manifest to {$path}.")
        ->assertExitCode(1);

    expect(File::exists($path))->toBeFalse();
});

test('the scan command fails clearly when the output parent path is not a directory', function () {
    $parentPath = necromancerScanTestPath('necromancer-parent-file');
    $path = $parentPath.'/manifest.json';

    File::put($parentPath, 'not a directory');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->expectsOutputToContain("Unable to write Necromancer manifest to {$path}.")
        ->assertExitCode(1);

    expect(File::exists($path))->toBeFalse();
});

test('the scan command fails clearly instead of crashing when a #[Necromancer] attribute value is invalid', function () {
    $path = necromancerScanTestPath('necromancer-invalid-annotation.json');

    app()->bind(
        JobCollector::class,
        fn ($app): JobCollector => new JobCollector($app, [[
            'path' => base_path('tests/Fixtures/InvalidJobs'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\InvalidJobs\\',
        ]]),
    );

    $exitCode = Artisan::call('necromancer:scan', ['--output' => $path]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Invalid Necromancer annotation')
        ->and($output)->toContain(NecromancerInvalidAnnotatedJob::class);

    expect(File::exists($path))->toBeFalse();
});

test('an existing manifest survives a scan that fails on an invalid #[Necromancer] attribute value', function () {
    $path = necromancerScanTestPath('necromancer-invalid-annotation-preserves-existing.json');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();
    $before = File::get($path);

    app()->bind(
        JobCollector::class,
        fn ($app): JobCollector => new JobCollector($app, [[
            'path' => base_path('tests/Fixtures/InvalidJobs'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\InvalidJobs\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path])->assertExitCode(1);

    expect(File::get($path))->toBe($before);
});

test('--diff also fails clearly instead of crashing on an invalid #[Necromancer] attribute value', function () {
    $path = necromancerScanTestPath('necromancer-invalid-annotation-diff.json');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();

    app()->bind(
        JobCollector::class,
        fn ($app): JobCollector => new JobCollector($app, [[
            'path' => base_path('tests/Fixtures/InvalidJobs'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\InvalidJobs\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path, '--diff' => true])
        ->expectsOutputToContain('Invalid Necromancer annotation')
        ->assertExitCode(1);
});

test('the --only option restricts the scan to the specified artifact type', function () {
    $path = necromancerScanTestPath('necromancer-only-routes.json');

    Route::get('/necromancer/only-routes-test', fn () => 'ok')->name('necromancer.only-routes-test');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'routes'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['routes'])
        ->and($manifest->artifacts->models ?? null)->toBeNull();
});

test('the --only option accepts multiple comma-separated types', function () {
    $path = necromancerScanTestPath('necromancer-only-models-jobs.json');

    useNecromancerFixtureModels();
    useNecromancerFixtureExecutables();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'models,jobs'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);
    $keys = array_keys((array) $manifest->artifacts);

    expect($keys)->toContain('models')
        ->and($keys)->toContain('jobs')
        ->and($manifest->artifacts->routes ?? null)->toBeNull()
        ->and($manifest->artifacts->events ?? null)->toBeNull();
});

test('unknown types in --only are silently ignored', function () {
    $path = necromancerScanTestPath('necromancer-only-unknown.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'models,nonexistent'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['models']);
});

test('--diff with no existing manifest fails with a clear error', function () {
    File::delete(base_path('necromancer.json'));

    $this->artisan('necromancer:scan', ['--diff' => true])
        ->expectsOutputToContain('No existing manifest found')
        ->assertFailed();
});

test('--diff with an unchanged manifest reports no changes', function () {
    $path = necromancerScanTestPath('necromancer-diff-unchanged.json');

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $this->artisan('necromancer:scan', ['--output' => $path, '--diff' => true])
        ->expectsOutputToContain('No changes detected.')
        ->assertSuccessful();
});

test('--diff reports added artifacts since last scan', function () {
    $path = necromancerScanTestPath('necromancer-diff-added.json');

    File::put($path, json_encode([
        'meta' => ['manifest_schema_version' => 1],
        'artifacts' => ['models' => []],
    ], JSON_THROW_ON_ERROR));

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path, '--diff' => true])
        ->expectsOutputToContain('+')
        ->assertSuccessful();
});

test('--diff does not write a new manifest to disk', function () {
    $path = necromancerScanTestPath('necromancer-diff-no-write.json');
    $originalContent = json_encode(['meta' => ['manifest_schema_version' => 1], 'artifacts' => (object) []], JSON_THROW_ON_ERROR);

    File::put($path, $originalContent);

    useNecromancerFixtureModels();

    $this->artisan('necromancer:scan', ['--output' => $path, '--diff' => true])
        ->assertSuccessful();

    expect(File::get($path))->toBe($originalContent);
});

test('the content_hash is identical across consecutive scans of the same codebase', function () {
    $path = necromancerScanTestPath('necromancer-content-hash-stability.json');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();
    $first = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();
    $second = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect($first->meta->content_hash)->toBe($second->meta->content_hash);
});

test('the scan command writes scheduled_task artifacts', function () {
    $path = necromancerScanTestPath('necromancer-scheduled-tasks-basic.json');

    $schedule = new Schedule;
    $schedule->command('inspire')->daily();
    $schedule->command('cache:clear')->hourly()->withoutOverlapping()->runInBackground();

    app()->bind(Schedule::class, fn (): Schedule => $schedule);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $task = findManifestScheduledTask($manifest, 'inspire');

    expect($task->command)->toBe('inspire')
        ->and($task->expression)->toBe('0 0 * * *')
        ->and($task->human_readable)->toBe('Daily')
        ->and($task->without_overlapping)->toBeFalse()
        ->and($task->run_in_background)->toBeFalse()
        ->and($task->even_in_maintenance)->toBeFalse();

    $cacheTask = findManifestScheduledTask($manifest, 'cache:clear');

    expect($cacheTask->without_overlapping)->toBeTrue()
        ->and($cacheTask->run_in_background)->toBeTrue()
        ->and($cacheTask->expression)->toBe('0 * * * *');
});

test('the --only=scheduled_tasks scan restricts to scheduled_task artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-scheduled-tasks.json');

    $schedule = new Schedule;
    $schedule->command('inspire')->daily();

    app()->bind(Schedule::class, fn (): Schedule => $schedule);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'scheduled_tasks'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['scheduled_tasks']);
});

test('the scan command handles closure-based scheduled tasks as Closure', function () {
    $path = necromancerScanTestPath('necromancer-scheduled_tasks-closure.json');

    $schedule = new Schedule;
    $schedule->call(fn () => null)->everyMinute()->description('Closure task');
    app()->bind(Schedule::class, fn () => $schedule);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    $task = findManifestScheduledTask($manifest, 'Closure');

    expect($task->command)->toBe('Closure')
        ->and($task->description)->toBe('Closure task');
});

test('the scan command collects middleware artifacts with valid scope and class fields', function () {
    $path = necromancerScanTestPath('necromancer-middleware-basic.json');

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    // The middleware key may or may not be present — if no app-level middleware is registered,
    // it may be absent. Either way the manifest must be structurally valid.
    foreach ($manifest->artifacts->middleware ?? [] as $item) {
        expect($item->alias)->toBeString()->not->toBeEmpty()
            ->and($item->class)->toBeString()->not->toBeEmpty()
            ->and($item->scope)->toBeIn(['global', 'group', 'alias']);

        if ($item->scope === 'group') {
            expect($item->group)->toBeString()->not->toBeEmpty();
        } else {
            expect($item->group)->toBeNull();
        }
    }
});

test('a middleware class annotation applies consistently to every registration of that class', function () {
    $path = necromancerScanTestPath('necromancer-middleware-annotations.json');

    // Resolving the Http Kernel syncs its own middleware groups onto the Router
    // (Kernel::__construct() -> syncMiddlewareToRouter()), which would otherwise
    // clobber a group pushed to beforehand. Force that sync first so the group
    // addition below survives the collector's own Kernel resolution.
    app(Illuminate\Contracts\Http\Kernel::class);

    app(Router::class)->aliasMiddleware('necromancer-annotated', NecromancerAnnotatedMiddleware::class);
    app(Router::class)->pushMiddlewareToGroup('web', NecromancerAnnotatedMiddleware::class);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'middleware'])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $registrations = array_values(array_filter(
        (array) ($manifest->artifacts->middleware ?? []),
        fn (stdClass $item): bool => $item->class === NecromancerAnnotatedMiddleware::class,
    ));

    expect($registrations)->toHaveCount(2)
        ->and($registrations[0]->scope)->not->toBe($registrations[1]->scope)
        ->and($registrations[0]->annotations)->toEqual((object) ['domain' => 'security', 'risk' => 'high'])
        ->and($registrations[1]->annotations)->toEqual((object) ['domain' => 'security', 'risk' => 'high']);
});

test('the --only=middleware scan restricts to middleware artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-middleware.json');

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'middleware'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    $keys = array_keys((array) $manifest->artifacts);

    expect($keys)->each->toBeIn(['middleware']);
});

test('the scan command collects livewire_components artifacts with correct properties, actions, listens, and source', function () {
    $path = necromancerScanTestPath('necromancer-livewire-basic.json');

    app()->bind(
        LivewireCollector::class,
        fn ($app): LivewireCollector => new LivewireCollector($app, [[
            'path' => base_path('tests/Fixtures/Livewire'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $component = findManifestLivewireComponent($manifest, NecromancerIssueForm::class);

    expect($component->class)->toBe(NecromancerIssueForm::class)
        ->and($component->view)->toBeString()->not->toBeEmpty()
        ->and($component->properties)->toBeArray()
        ->and(array_column((array) $component->properties, 'name'))->toContain('title', 'count')
        ->and($component->actions)->toContain('save')
        ->and($component->actions)->not->toContain('render')
        ->and($component->listens)->toContain('issue-updated')
        ->and($component->source->file)->toContain('Livewire/NecromancerIssueForm.php')
        ->and($component->source->line)->toBeInt();
});

test('the --only=livewire_components scan restricts to livewire_components artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-livewire.json');

    app()->bind(
        LivewireCollector::class,
        fn ($app): LivewireCollector => new LivewireCollector($app, [[
            'path' => base_path('tests/Fixtures/Livewire'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Livewire\\',
        ]]),
    );

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'livewire_components'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['livewire_components']);
});

test('the scan command collects gate artifacts with correct kind and parameters', function () {
    $path = necromancerScanTestPath('necromancer-gates-basic.json');

    $gate = new Gate(app(), fn () => null);
    $gate->define('edit-post', function ($user, string $postId): bool {
        return true;
    });
    $gate->define('view-admin', function ($user): bool {
        return true;
    });
    app()->bind(Gate::class, fn () => $gate);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $editPost = findManifestGate($manifest, 'edit-post');
    $viewAdmin = findManifestGate($manifest, 'view-admin');

    expect($editPost->kind)->toBe('closure')
        ->and($editPost->parameters)->toBe(['string'])
        ->and($viewAdmin->kind)->toBe('closure')
        ->and($viewAdmin->parameters)->toBe([]);
});

test('the scan command collects a class-string gate with kind=class', function () {
    $path = necromancerScanTestPath('necromancer-gates-class.json');

    $gate = new Gate(app(), fn () => null);
    $gate->define('manage-users', NecromancerManageUsersGate::class);
    app()->bind(Gate::class, fn () => $gate);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);
    $manageUsers = findManifestGate($manifest, 'manage-users');

    expect($manageUsers->kind)->toBe('class');
});

test('the scan command collects before hook artifacts with kind=before_hook', function () {
    $path = necromancerScanTestPath('necromancer-gates-before-hook.json');

    $gate = new Gate(app(), fn () => null);
    $gate->before(function ($user, string $ability): ?bool {
        return null;
    });
    app()->bind(Gate::class, fn () => $gate);

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $beforeHooks = array_values(array_filter(
        $manifest->artifacts->gates ?? [],
        fn (stdClass $gate): bool => $gate->kind === 'before_hook',
    ));

    expect($beforeHooks)->toHaveCount(1)
        ->and($beforeHooks[0]->ability)->toBe('__before__')
        ->and($beforeHooks[0]->parameters)->toBe(['string']);
});

test('the --only=gates scan restricts to gate artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-gates.json');

    $gate = new Gate(app(), fn () => null);
    $gate->define('view-admin', function ($user): bool {
        return true;
    });
    app()->bind(Gate::class, fn () => $gate);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'gates'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['gates']);
});

test('the scan command collects mailable artifacts with correct subject, queued, queue, and view', function () {
    $path = necromancerScanTestPath('necromancer-mailables-basic.json');

    useNecromancerFixtureMailables();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $welcome = findManifestMailable($manifest, NecromancerWelcomeMail::class);

    expect($welcome->subject)->toBe('Welcome!')
        ->and($welcome->queued)->toBeTrue()
        ->and($welcome->queue)->toBe('notifications')
        ->and($welcome->view)->toBe('mail.welcome');

    $reset = findManifestMailable($manifest, NecromancerPasswordResetMail::class);

    expect($reset->subject)->toBe('Reset your password')
        ->and($reset->queued)->toBeFalse()
        ->and($reset->queue)->toBeNull()
        ->and($reset->view)->toBe('mail.password-reset');
});

test('the --only=mailables scan restricts to mailable artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-mailables.json');

    useNecromancerFixtureMailables();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'mailables'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['mailables']);
});

test('the scan command collects validation_rules artifacts with correct implicit flag, description, and source', function () {
    $path = necromancerScanTestPath('necromancer-validation-rules-basic.json');

    useNecromancerFixtureRules();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $unique = findManifestValidationRule($manifest, NecromancerUniqueInProjectRule::class);

    expect($unique->implicit)->toBeFalse()
        ->and($unique->description)->toBe('Validates that a value is unique within a project.')
        ->and($unique->source->file)->toContain('Fixtures/Rules/NecromancerUniqueInProjectRule.php')
        ->and($unique->source->line)->toBeInt();

    $required = findManifestValidationRule($manifest, NecromancerRequiredIfMemberRule::class);

    expect($required->implicit)->toBeTrue()
        ->and($required->description)->toBeNull();
});

test('the --only=validation_rules scan restricts to validation rule artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-validation-rules.json');

    useNecromancerFixtureRules();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'validation_rules'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['validation_rules']);
});

test('the scan command collects service_provider artifacts', function () {
    $path = necromancerScanTestPath('necromancer-service-providers-basic.json');

    useNecromancerFixtureProviders();

    $this->artisan('necromancer:scan', ['--output' => $path])
        ->assertSuccessful();

    $manifest = expectScanManifest($path);

    $provider = findManifestServiceProvider($manifest, NecromancerFixtureServiceProvider::class);

    expect($provider->class)->toBe(NecromancerFixtureServiceProvider::class)
        ->and($provider->deferred)->toBeFalse()
        ->and($provider->bindings)->toBeArray()
        ->and($provider->singletons)->toBeArray()
        ->and($provider->source->file)->toContain('NecromancerFixtureServiceProvider.php');
});

test('the --only=service_providers scan restricts to service_provider artifacts', function () {
    $path = necromancerScanTestPath('necromancer-only-service-providers.json');

    useNecromancerFixtureProviders();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'service_providers'])
        ->assertSuccessful();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect(array_keys((array) $manifest->artifacts))->toBe(['service_providers']);
});

test('an exact-ID mapping annotates a gate ability', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-gate.json');

    $gate = new Gate(app(), fn () => null);
    $gate->define('edit-post', function ($user): bool {
        return true;
    });
    app()->bind(Gate::class, fn () => $gate);

    config(['necromancer.annotations' => [
        'gates:ability:edit-post' => ['domain' => 'content', 'risk' => 'low'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'gates'])->assertSuccessful();

    $gateArtifact = findManifestGate(expectScanManifest($path), 'edit-post');

    expect($gateArtifact->annotations)->toEqual((object) ['domain' => 'content', 'risk' => 'low']);
});

test('an exact-ID mapping annotates a scheduled task using its discovered ID', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-scheduled-task.json');

    $schedule = new Schedule;
    $schedule->command('inspire')->daily();
    app()->bind(Schedule::class, fn (): Schedule => $schedule);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'scheduled_tasks'])->assertSuccessful();
    $taskId = findManifestScheduledTask(expectScanManifest($path), 'inspire')->id;

    config(['necromancer.annotations' => [
        $taskId => ['domain' => 'ops'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'scheduled_tasks'])->assertSuccessful();

    $task = findManifestScheduledTask(expectScanManifest($path), 'inspire');

    expect($task->annotations)->toEqual((object) ['domain' => 'ops']);
});

test('an exact-ID mapping annotates a test file artifact', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-test-file.json');

    useNecromancerFixtureTests();

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'tests'])->assertSuccessful();
    $testId = findManifestTestByFilename(expectScanManifest($path), 'NecromancerFunctionalTest.php')->id;

    config(['necromancer.annotations' => [
        $testId => ['domain' => 'quality'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'tests'])->assertSuccessful();

    $test = findManifestTestByFilename(expectScanManifest($path), 'NecromancerFunctionalTest.php');

    expect($test->annotations)->toEqual((object) ['domain' => 'quality']);
});

test('an exact-ID mapping annotates a closure route with no other annotation source', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-closure-route.json');

    Route::post('/necromancer/annotations-config/closure', function () {
        return 'ok';
    });

    config(['necromancer.annotations' => [
        'routes:POST:necromancer/annotations-config/closure' => ['domain' => 'support', 'risk' => 'low'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'routes'])->assertSuccessful();

    $route = findManifestRouteByUri(expectScanManifest($path), 'necromancer/annotations-config/closure');

    expect($route->annotations)->toEqual((object) ['domain' => 'support', 'risk' => 'low']);
});

test('an exact-ID mapping adds registration-specific annotations to one middleware registration without affecting others', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-middleware.json');

    app(Illuminate\Contracts\Http\Kernel::class);
    app(Router::class)->aliasMiddleware('necromancer-annotated-config', NecromancerAnnotatedMiddleware::class);
    app(Router::class)->pushMiddlewareToGroup('web', NecromancerAnnotatedMiddleware::class);

    config(['necromancer.annotations' => [
        'middleware:alias:necromancer-annotated-config' => ['capability' => 'security.two-factor'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'middleware'])->assertSuccessful();

    $manifest = expectScanManifest($path);
    $alias = findManifestMiddleware($manifest, 'necromancer-annotated-config');
    $group = array_values(array_filter(
        (array) ($manifest->artifacts->middleware ?? []),
        fn (stdClass $item): bool => $item->class === NecromancerAnnotatedMiddleware::class && $item->scope === 'group',
    ))[0];

    expect($alias->annotations)->toEqual((object) [
        'domain' => 'security',
        'risk' => 'high',
        'capability' => 'security.two-factor',
    ])->and($group->annotations)->toEqual((object) ['domain' => 'security', 'risk' => 'high']);
});

test('an exact-ID mapping cannot override an existing scalar and the conflict is diagnosed', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-conflict.json');

    app(Illuminate\Contracts\Http\Kernel::class);
    app(Router::class)->pushMiddlewareToGroup('web', NecromancerAnnotatedMiddleware::class);

    $groupId = 'middleware:group:web:'.NecromancerAnnotatedMiddleware::class;

    config(['necromancer.annotations' => [
        $groupId => ['domain' => 'platform'],
    ]]);

    // expectsOutputToContain() checks each individual write call rather than the
    // full buffer, which is unreliable for long strings — Artisan::output() is
    // used instead, matching the canonical-ID assertion further up this file.
    $exitCode = Artisan::call('necromancer:scan', ['--output' => $path, '--only' => 'middleware']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('AN_SOURCE_CONFLICT')
        ->and($output)->toContain($groupId);

    $manifest = expectScanManifest($path);
    $group = array_values(array_filter(
        (array) ($manifest->artifacts->middleware ?? []),
        fn (stdClass $item): bool => $item->class === NecromancerAnnotatedMiddleware::class && $item->scope === 'group',
    ))[0];

    expect($group->annotations)->toEqual((object) ['domain' => 'security', 'risk' => 'high']);
});

test('an exact-ID mapping with no matching artifact in scope emits AN_CONFIG_UNMATCHED', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-unmatched.json');

    config(['necromancer.annotations' => [
        'gates:ability:does-not-exist' => ['domain' => 'content'],
    ]]);

    $exitCode = Artisan::call('necromancer:scan', ['--output' => $path, '--only' => 'gates']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('AN_CONFIG_UNMATCHED')
        ->and($output)->toContain('gates:ability:does-not-exist');
});

test('an exact-ID mapping outside a partial scan scope emits no warning', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-out-of-scope.json');

    config(['necromancer.annotations' => [
        'jobs:App\\Jobs\\DoesNotExist' => ['domain' => 'billing'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'routes'])
        ->doesntExpectOutputToContain('AN_CONFIG_UNMATCHED')
        ->assertSuccessful();
});

test('a malformed exact-ID mapping outside a partial scan scope does not fail the scan', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-malformed-out-of-scope.json');

    config(['necromancer.annotations' => [
        'jobs:App\\Jobs\\*' => ['domain' => 'billing'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'routes'])->assertSuccessful();
});

test('the scan command fails clearly when an exact-ID mapping key contains a wildcard', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-wildcard.json');

    config(['necromancer.annotations' => [
        'jobs:App\\Jobs\\*' => ['domain' => 'billing'],
    ]]);

    $exitCode = Artisan::call('necromancer:scan', ['--output' => $path]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('AN_SCHEMA_INVALID_VALUE');

    expect(File::exists($path))->toBeFalse();
});

test('the scan command fails clearly when an exact-ID mapping declares an unknown field', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-unknown-field.json');

    config(['necromancer.annotations' => [
        'jobs:App\\Jobs\\SendInvoice' => ['owner' => 'platform-team'],
    ]]);

    $exitCode = Artisan::call('necromancer:scan', ['--output' => $path]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('AN_SCHEMA_UNKNOWN_FIELD');

    expect(File::exists($path))->toBeFalse();
});

test('an existing manifest survives a scan that fails on an invalid exact-ID mapping risk value', function () {
    $path = necromancerScanTestPath('necromancer-annotations-config-invalid-risk.json');

    $this->artisan('necromancer:scan', ['--output' => $path])->assertSuccessful();
    $before = File::get($path);

    config(['necromancer.annotations' => [
        'jobs:App\\Jobs\\SendInvoice' => ['risk' => 'urgent'],
    ]]);

    $this->artisan('necromancer:scan', ['--output' => $path])->assertExitCode(1);

    expect(File::get($path))->toBe($before);
});

function expectMinimalScanManifest(string $path): void
{
    expectScanManifest($path);
}

function expectScanManifest(string $path): stdClass
{
    expect(File::exists($path))->toBeTrue();

    $manifest = json_decode((string) File::get($path), false, 512, JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toHaveProperty('meta')
        ->toHaveProperty('artifacts');

    expect($manifest->meta)
        ->toHaveProperty('manifest_schema_version', 1)
        ->toHaveProperty('annotation_schema_version', 1)
        ->toHaveProperty('scope')
        ->toHaveProperty('generated_at')
        ->toHaveProperty('content_hash')
        ->toHaveProperty('necromancer_version')
        ->toHaveProperty('laravel_version')
        ->toHaveProperty('php_version')
        ->toHaveProperty('app_name')
        ->toHaveProperty('app_url')
        ->toHaveProperty('app_env');

    expect($manifest->meta->generated_at)->toBeString()
        ->and($manifest->meta->content_hash)->toBeString()->toHaveLength(64)
        ->and($manifest->meta->necromancer_version)->toBeString()
        ->and($manifest->meta->laravel_version)->toBe(app()->version())
        ->and($manifest->meta->php_version)->toBe(PHP_VERSION)
        ->and($manifest->meta->app_name)->toBe(config('app.name'))
        ->and($manifest->meta->app_url)->toBe(config('app.url'))
        ->and($manifest->meta->app_env)->toBe(app()->environment());

    expect($manifest->meta->scope->complete)->toBeBool()
        ->and($manifest->meta->scope->artifact_types)->toBeArray();

    foreach ((array) $manifest->artifacts as $items) {
        foreach ($items as $artifact) {
            expect($artifact->id)->toBeString()->not->toBeEmpty();
        }
    }

    expect(array_keys((array) $manifest->artifacts))->each->toBeIn([
        'commands',
        'enums',
        'events',
        'form_requests',
        'gates',
        'jobs',
        'listeners',
        'livewire_components',
        'mailables',
        'middleware',
        'models',
        'observers',
        'policies',
        'routes',
        'scheduled_tasks',
        'service_providers',
        'tests',
        'validation_rules',
    ]);

    return $manifest;
}

function findManifestRouteByName(stdClass $manifest, string $name): stdClass
{
    return findManifestRoute($manifest, static fn (stdClass $route): bool => $route->name === $name);
}

function findManifestRouteByUri(stdClass $manifest, string $uri): stdClass
{
    return findManifestRoute($manifest, static fn (stdClass $route): bool => $route->uri === $uri);
}

/**
 * @param  callable(stdClass): bool  $callback
 */
function findManifestRoute(stdClass $manifest, callable $callback): stdClass
{
    foreach ($manifest->artifacts->routes ?? [] as $route) {
        if ($callback($route)) {
            return $route;
        }
    }

    Assert::fail('Expected route artifact was not found.');
}

/**
 * @return list<string|null>
 */
function manifestRouteNames(stdClass $manifest): array
{
    return array_map(
        static fn (stdClass $route): ?string => $route->name,
        $manifest->artifacts->routes ?? [],
    );
}

function manifestRouteUris(stdClass $manifest): array
{
    return array_map(
        static fn (stdClass $route): string => $route->uri,
        $manifest->artifacts->routes ?? [],
    );
}

function useNecromancerFixtureTests(): void
{
    app()->bind(
        TestCollector::class,
        fn ($app): TestCollector => new TestCollector($app, [[
            'path' => base_path('tests/Fixtures/Tests'),
            'type' => 'unit',
        ]]),
    );
}

function useNecromancerFixtureModels(): void
{
    app()->bind(
        ModelCollector::class,
        fn ($app): ModelCollector => new ModelCollector($app, [[
            'path' => base_path('tests/Fixtures/Models'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Models\\',
        ]]),
    );
}

function useNecromancerFixtureExecutables(): void
{
    $eventRoots = [[
        'path' => base_path('tests/Fixtures/Events'),
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Events\\',
    ]];
    $listenerRoots = [[
        'path' => base_path('tests/Fixtures/Listeners'),
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Listeners\\',
    ]];

    app()->bind(
        JobCollector::class,
        fn ($app): JobCollector => new JobCollector($app, [[
            'path' => base_path('tests/Fixtures/Jobs'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Jobs\\',
        ]]),
    );

    app()->bind(
        EventCollector::class,
        fn ($app): EventCollector => new EventCollector($app, $eventRoots, $listenerRoots),
    );

    app()->bind(
        ListenerCollector::class,
        fn ($app): ListenerCollector => new ListenerCollector($app, $listenerRoots, $eventRoots),
    );

    app()->bind(
        CommandCollector::class,
        fn ($app): CommandCollector => new CommandCollector($app, [[
            'path' => base_path('tests/Fixtures/Commands'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Commands\\',
        ]]),
    );

    app(Kernel::class)->registerCommand(new NecromancerFixtureCommand);
}

function findManifestModel(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->models ?? [] as $model) {
        if ($model->class === $class) {
            return $model;
        }
    }

    Assert::fail("Expected model artifact [{$class}] was not found.");
}

/**
 * @return list<string>
 */
function manifestModelClasses(stdClass $manifest): array
{
    return array_map(
        static fn (stdClass $model): string => $model->class,
        $manifest->artifacts->models ?? [],
    );
}

function findManifestJob(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->jobs ?? [] as $job) {
        if ($job->class === $class) {
            return $job;
        }
    }

    Assert::fail("Expected job artifact [{$class}] was not found.");
}

function findManifestEvent(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->events ?? [] as $event) {
        if ($event->class === $class) {
            return $event;
        }
    }

    Assert::fail("Expected event artifact [{$class}] was not found.");
}

function findManifestListener(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->listeners ?? [] as $listener) {
        if ($listener->class === $class) {
            return $listener;
        }
    }

    Assert::fail("Expected listener artifact [{$class}] was not found.");
}

function useNecromancerFixtureRequests(): void
{
    app()->bind(
        FormRequestCollector::class,
        fn ($app): FormRequestCollector => new FormRequestCollector($app, [[
            'path' => base_path('tests/Fixtures/Requests'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Requests\\',
        ]]),
    );
}

function findManifestFormRequest(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->form_requests ?? [] as $request) {
        if ($request->class === $class) {
            return $request;
        }
    }

    Assert::fail("Expected form request artifact [{$class}] was not found.");
}

function findManifestCommand(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->commands ?? [] as $command) {
        if ($command->class === $class) {
            return $command;
        }
    }

    Assert::fail("Expected command artifact [{$class}] was not found.");
}

/**
 * @return list<string>
 */
function manifestCommandClasses(stdClass $manifest): array
{
    return array_map(
        static fn (stdClass $command): string => $command->class,
        $manifest->artifacts->commands ?? [],
    );
}

/**
 * @return list<string>
 */
function manifestCommandSignatures(stdClass $manifest): array
{
    return array_map(
        static fn (stdClass $command): string => $command->signature,
        $manifest->artifacts->commands ?? [],
    );
}

function findManifestEnum(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->enums ?? [] as $enum) {
        if ($enum->class === $class) {
            return $enum;
        }
    }

    Assert::fail("Expected enum artifact [{$class}] was not found.");
}

function findManifestPolicy(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->policies ?? [] as $policy) {
        if ($policy->class === $class) {
            return $policy;
        }
    }

    Assert::fail("Expected policy artifact [{$class}] was not found.");
}

function findManifestTestByFilename(stdClass $manifest, string $filename): stdClass
{
    foreach ($manifest->artifacts->tests ?? [] as $test) {
        if (str_ends_with((string) ($test->file ?? ''), $filename)) {
            return $test;
        }
    }

    Assert::fail("Expected test artifact with filename [{$filename}] was not found.");
}

function findManifestObserver(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->observers ?? [] as $observer) {
        if ($observer->class === $class) {
            return $observer;
        }
    }

    Assert::fail("Expected observer artifact [{$class}] was not found.");
}

function findManifestScheduledTask(stdClass $manifest, string $command): stdClass
{
    foreach ($manifest->artifacts->scheduled_tasks ?? [] as $task) {
        if ($task->command === $command) {
            return $task;
        }
    }

    Assert::fail("Expected scheduled task artifact [{$command}] was not found.");
}

function necromancerScanTestPath(string $filename): string
{
    return storage_path("framework/testing/{$filename}");
}

function findManifestLivewireComponent(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->livewire_components ?? [] as $component) {
        if ($component->class === $class) {
            return $component;
        }
    }

    Assert::fail("Expected livewire_components artifact [{$class}] was not found.");
}

function findManifestMiddleware(stdClass $manifest, string $alias): stdClass
{
    foreach ($manifest->artifacts->middleware ?? [] as $middleware) {
        if ($middleware->alias === $alias) {
            return $middleware;
        }
    }

    Assert::fail("Expected middleware artifact with alias [{$alias}] was not found.");
}

function findManifestGate(stdClass $manifest, string $ability): stdClass
{
    foreach ($manifest->artifacts->gates ?? [] as $gate) {
        if ($gate->ability === $ability) {
            return $gate;
        }
    }

    Assert::fail("Expected gate artifact with ability [{$ability}] was not found.");
}

function useNecromancerFixtureMailables(): void
{
    app()->bind(
        MailableCollector::class,
        fn ($app): MailableCollector => new MailableCollector($app, [[
            'path' => base_path('tests/Fixtures/Mail'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Mail\\',
        ]]),
    );
}

function findManifestMailable(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->mailables ?? [] as $mailable) {
        if ($mailable->class === $class) {
            return $mailable;
        }
    }

    Assert::fail("Expected mailable artifact [{$class}] was not found.");
}

function useNecromancerFixtureRules(): void
{
    app()->bind(
        RuleCollector::class,
        fn ($app): RuleCollector => new RuleCollector($app, [[
            'path' => base_path('tests/Fixtures/Rules'),
            'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\Rules\\',
        ]]),
    );
}

function findManifestValidationRule(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->validation_rules ?? [] as $rule) {
        if ($rule->class === $class) {
            return $rule;
        }
    }

    Assert::fail("Expected validation_rule artifact [{$class}] was not found.");
}

function useNecromancerFixtureProviders(): void
{
    $fixtureFile = base_path('tests/Fixtures/Providers/NecromancerFixtureServiceProvider.php');
    $providersFile = base_path('bootstrap/providers.php');

    // Load the fixture class (not in PSR-4 autoloader since it uses App\ namespace)
    require_once $fixtureFile;

    File::put($providersFile, "<?php\nreturn [\n    \\App\\Providers\\NecromancerFixtureServiceProvider::class,\n];\n");

    test()->afterEach(fn () => File::delete($providersFile));
}

function findManifestServiceProvider(stdClass $manifest, string $class): stdClass
{
    foreach ($manifest->artifacts->service_providers ?? [] as $provider) {
        if ($provider->class === $class) {
            return $provider;
        }
    }

    Assert::fail("Expected service_provider artifact [{$class}] was not found.");
}

function cleanNecromancerScanTestFiles(): void
{
    File::delete([
        base_path('necromancer.json'),
        necromancerScanTestPath('necromancer-configured-output.json'),
        necromancerScanTestPath('necromancer-relative-output.json'),
        necromancerScanTestPath('necromancer-absolute-output.json'),
        necromancerScanTestPath('necromancer-configured-precedence.json'),
        necromancerScanTestPath('necromancer-option-precedence.json'),
        necromancerScanTestPath('necromancer-routes-closure.json'),
        necromancerScanTestPath('necromancer-routes-methods.json'),
        necromancerScanTestPath('necromancer-routes-controller.json'),
        necromancerScanTestPath('necromancer-routes-invokable.json'),
        necromancerScanTestPath('necromancer-routes-default-exclusions.json'),
        necromancerScanTestPath('necromancer-routes-configured-exclusions.json'),
        necromancerScanTestPath('necromancer-models-basic.json'),
        necromancerScanTestPath('necromancer-models-relationships.json'),
        necromancerScanTestPath('necromancer-models-skips-non-models.json'),
        necromancerScanTestPath('necromancer-models-exclusions.json'),
        necromancerScanTestPath('necromancer-models-extended.json'),
        necromancerScanTestPath('necromancer-models-no-hidden.json'),
        necromancerScanTestPath('necromancer-jobs-basic.json'),
        necromancerScanTestPath('necromancer-form-requests-basic.json'),
        necromancerScanTestPath('necromancer-only-form-requests.json'),
        necromancerScanTestPath('necromancer-events-listeners.json'),
        necromancerScanTestPath('necromancer-commands-basic.json'),
        necromancerScanTestPath('necromancer-diff-unchanged.json'),
        necromancerScanTestPath('necromancer-diff-added.json'),
        necromancerScanTestPath('necromancer-diff-no-write.json'),
        necromancerScanTestPath('necromancer-enums-backed.json'),
        necromancerScanTestPath('necromancer-only-enums.json'),
        necromancerScanTestPath('necromancer-tests-basic.json'),
        necromancerScanTestPath('necromancer-tests-uses.json'),
        necromancerScanTestPath('necromancer-tests-class-based.json'),
        necromancerScanTestPath('necromancer-only-tests.json'),
        necromancerScanTestPath('necromancer-policies-basic.json'),
        necromancerScanTestPath('necromancer-only-policies.json'),
        necromancerScanTestPath('necromancer-models-guarded.json'),
        necromancerScanTestPath('necromancer-jobs-timeout.json'),
        necromancerScanTestPath('necromancer-events-broadcastable.json'),
        necromancerScanTestPath('necromancer-only-routes.json'),
        necromancerScanTestPath('necromancer-only-models-jobs.json'),
        necromancerScanTestPath('necromancer-only-unknown.json'),
        necromancerScanTestPath('necromancer-parent-file'),
        necromancerScanTestPath('necromancer-observers-basic.json'),
        necromancerScanTestPath('necromancer-only-observers.json'),
        necromancerScanTestPath('necromancer-scheduled-tasks-basic.json'),
        necromancerScanTestPath('necromancer-only-scheduled-tasks.json'),
        necromancerScanTestPath('necromancer-scheduled_tasks-closure.json'),
        necromancerScanTestPath('necromancer-middleware-basic.json'),
        necromancerScanTestPath('necromancer-only-middleware.json'),
        necromancerScanTestPath('necromancer-livewire-basic.json'),
        necromancerScanTestPath('necromancer-only-livewire.json'),
        necromancerScanTestPath('necromancer-gates-basic.json'),
        necromancerScanTestPath('necromancer-only-gates.json'),
        necromancerScanTestPath('necromancer-gates-class.json'),
        necromancerScanTestPath('necromancer-gates-before-hook.json'),
        necromancerScanTestPath('necromancer-mailables-basic.json'),
        necromancerScanTestPath('necromancer-only-mailables.json'),
        necromancerScanTestPath('necromancer-validation-rules-basic.json'),
        necromancerScanTestPath('necromancer-only-validation-rules.json'),
        necromancerScanTestPath('necromancer-service-providers-basic.json'),
        necromancerScanTestPath('necromancer-only-service-providers.json'),
    ]);

    File::deleteDirectory(storage_path('framework/testing/missing-necromancer-output'));
}
