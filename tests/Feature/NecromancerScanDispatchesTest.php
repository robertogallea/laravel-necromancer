<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use LaravelNecromancer\Collection\ActionCollector;
use LaravelNecromancer\Collection\ControllerCollector;
use LaravelNecromancer\Collection\EventCollector;
use LaravelNecromancer\Collection\JobCollector;
use LaravelNecromancer\Collection\ListenerCollector;
use LaravelNecromancer\Collection\MailableCollector;
use LaravelNecromancer\Collection\ModelCollector;

const DISPATCH_APP = 'LaravelNecromancer\\Tests\\Fixtures\\DispatchApp\\';

beforeEach(function () {
    useDispatchFixtureApp();
});

test('the scan records what controllers, actions, jobs, listeners and models dispatch', function () {
    $manifest = scanDispatchFixtureApp('necromancer-dispatches.json');

    expect(dispatchesOf($manifest, 'controllers', 'Http\\Controllers\\DispatchOrderController'))->toBe([
        ['target' => DISPATCH_APP.'Jobs\\ShipDispatchOrder', 'method' => 'store', 'mode' => 'queued'],
    ])->and(dispatchesOf($manifest, 'actions', 'Actions\\PlaceDispatchOrder'))->toBe([
        ['target' => DISPATCH_APP.'Jobs\\ShipDispatchOrder', 'method' => 'handle', 'mode' => 'queued'],
        ['target' => 'Vendor\\Billing\\ChargeCustomer', 'method' => 'handle', 'mode' => 'queued'],
    ])->and(dispatchesOf($manifest, 'jobs', 'Jobs\\ShipDispatchOrder'))->toBe([
        ['target' => DISPATCH_APP.'Events\\DispatchOrderPlaced', 'method' => 'handle', 'mode' => null],
        ['target' => DISPATCH_APP.'Mail\\DispatchOrderShipped', 'method' => 'handle', 'mode' => 'queued'],
    ])->and(dispatchesOf($manifest, 'listeners', 'Listeners\\ShipAfterDispatchOrderPlaced'))->toBe([
        ['target' => DISPATCH_APP.'Jobs\\ShipDispatchOrder', 'method' => 'handle', 'mode' => 'sync'],
    ])->and(dispatchesOf($manifest, 'models', 'Models\\DispatchOrder'))->toBe([
        ['target' => DISPATCH_APP.'Events\\DispatchOrderPlaced', 'method' => 'booted', 'mode' => null],
    ]);
});

test('artifacts that dispatch nothing carry no dispatches key', function () {
    $manifest = scanDispatchFixtureApp('necromancer-dispatches-absent.json');

    expect(dispatchArtifact($manifest, 'events', 'Events\\DispatchOrderPlaced'))->not->toHaveKey('dispatches')
        ->and(dispatchArtifact($manifest, 'mailables', 'Mail\\DispatchOrderShipped'))->not->toHaveKey('dispatches');
});

test('two consecutive scans of an unchanged app give an identical content_hash', function () {
    $first = scanDispatchFixtureApp('necromancer-dispatches-hash.json');
    $second = scanDispatchFixtureApp('necromancer-dispatches-hash.json');

    expect($first['meta']['content_hash'])->toBe($second['meta']['content_hash']);
});

test('an unparseable artifact source reports DS_PARSE_FAILED, still succeeds, and records no dispatches', function () {
    $directory = base_path('storage/framework/testing/dispatch-broken');
    File::ensureDirectoryExists($directory);
    $file = "{$directory}/BrokenDispatchJob.php";
    File::put($file, <<<'PHP'
        <?php

        namespace LaravelNecromancer\Tests\Fixtures\DispatchBroken;

        final class BrokenDispatchJob implements \Illuminate\Contracts\Queue\ShouldQueue
        {
            public function handle(): void {}
        }
        PHP);
    require_once $file;
    // The class is loaded; now its source text stops being valid PHP.
    File::put($file, "<?php\n\nfinal class BrokenDispatchJob {\n    public function handle( {\n");

    app()->bind(JobCollector::class, fn ($app): JobCollector => new JobCollector($app, [[
        'path' => $directory,
        'namespace' => 'LaravelNecromancer\\Tests\\Fixtures\\DispatchBroken\\',
    ]]));

    $path = base_path('storage/framework/testing/necromancer-dispatches-broken.json');

    try {
        $this->artisan('necromancer:scan', ['--output' => $path, '--only' => 'jobs'])
            ->expectsOutputToContain('DS_PARSE_FAILED: jobs:LaravelNecromancer\\Tests\\Fixtures\\DispatchBroken\\BrokenDispatchJob')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($directory);
    }

    $manifest = json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['artifacts']['jobs'][0]['class'])->toBe('LaravelNecromancer\\Tests\\Fixtures\\DispatchBroken\\BrokenDispatchJob')
        ->and($manifest['artifacts']['jobs'][0])->not->toHaveKey('dispatches');
});

function useDispatchFixtureApp(): void
{
    $root = fn (string $folder): array => [[
        'path' => base_path('tests/Fixtures/DispatchApp/'.str_replace('\\', '/', $folder)),
        'namespace' => DISPATCH_APP.$folder.'\\',
    ]];

    app()->bind(ControllerCollector::class, fn ($app): ControllerCollector => new ControllerCollector(
        $app,
        $app->make(Router::class),
        $root('Http\\Controllers'),
        DISPATCH_APP,
    ));
    app()->bind(ActionCollector::class, fn ($app): ActionCollector => new ActionCollector($app, $root('Actions')));
    app()->bind(JobCollector::class, fn ($app): JobCollector => new JobCollector($app, $root('Jobs')));
    app()->bind(EventCollector::class, fn ($app): EventCollector => new EventCollector($app, $root('Events'), $root('Listeners')));
    app()->bind(ListenerCollector::class, fn ($app): ListenerCollector => new ListenerCollector($app, $root('Listeners'), $root('Events')));
    app()->bind(ModelCollector::class, fn ($app): ModelCollector => new ModelCollector($app, $root('Models')));
    app()->bind(MailableCollector::class, fn ($app): MailableCollector => new MailableCollector($app, $root('Mail')));
}

/**
 * @return array<string, mixed>
 */
function scanDispatchFixtureApp(string $filename): array
{
    $path = base_path("storage/framework/testing/{$filename}");

    test()->artisan('necromancer:scan', [
        '--output' => $path,
        '--only' => 'controllers,actions,jobs,events,listeners,models,mailables',
    ])->assertSuccessful();

    return json_decode((string) File::get($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $manifest
 * @return array<string, mixed>
 */
function dispatchArtifact(array $manifest, string $type, string $class): array
{
    foreach ($manifest['artifacts'][$type] ?? [] as $artifact) {
        if ($artifact['class'] === DISPATCH_APP.$class) {
            return $artifact;
        }
    }

    throw new RuntimeException("No {$type} artifact for {$class}.");
}

/**
 * @param  array<string, mixed>  $manifest
 * @return list<array<string, mixed>>|null
 */
function dispatchesOf(array $manifest, string $type, string $class): ?array
{
    return dispatchArtifact($manifest, $type, $class)['dispatches'] ?? null;
}
