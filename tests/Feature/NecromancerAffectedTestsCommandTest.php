<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use LaravelNecromancer\Commands\AffectedTestsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @param  array<string, list<array<string, mixed>>>  $artifacts
 */
function writeAffectedTestsManifest(array $artifacts, bool $complete = true): void
{
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1,
            'generated_at' => now()->addMinute()->toIso8601String(),
            'scope' => ['complete' => $complete, 'artifact_types' => array_keys($artifacts)],
        ],
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
}

/**
 * Order, its policy, a route and controller sharing a file, a job, and
 * tests of the model, the policy, the controller, and the Jobs namespace.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function affectedTestsCommandArtifacts(): array
{
    return [
        'routes' => [[
            'id' => 'routes:GET:orders',
            'method' => 'GET',
            'uri' => 'orders',
            'controller' => 'App\\Http\\Controllers\\OrderController',
            'action' => 'index',
            'source' => ['file' => 'app/Http/Controllers/OrderController.php'],
        ]],
        'controllers' => [[
            'id' => 'controllers:App\\Http\\Controllers\\OrderController',
            'class' => 'App\\Http\\Controllers\\OrderController',
            'source' => ['file' => 'app/Http/Controllers/OrderController.php'],
        ]],
        'models' => [['id' => 'models:App\\Models\\Order', 'class' => 'App\\Models\\Order', 'policy' => 'App\\Policies\\OrderPolicy', 'source' => ['file' => 'app/Models/Order.php']]],
        'jobs' => [['id' => 'jobs:App\\Jobs\\ShipOrder', 'class' => 'App\\Jobs\\ShipOrder', 'source' => ['file' => 'app/Jobs/ShipOrder.php']]],
        'policies' => [['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy', 'model' => 'App\\Models\\Order', 'source' => ['file' => 'app/Policies/OrderPolicy.php']]],
        'tests' => [
            ['id' => 'tests:tests/Unit/OrderTest.php', 'file' => 'tests/Unit/OrderTest.php', 'subject' => 'App\\Models\\Order', 'source' => ['file' => 'tests/Unit/OrderTest.php']],
            ['id' => 'tests:tests/Unit/OrderPolicyTest.php', 'file' => 'tests/Unit/OrderPolicyTest.php', 'subject' => 'App\\Policies\\OrderPolicy', 'source' => ['file' => 'tests/Unit/OrderPolicyTest.php']],
            ['id' => 'tests:tests/Feature/OrderControllerTest.php', 'file' => 'tests/Feature/OrderControllerTest.php', 'subject' => 'App\\Http\\Controllers\\OrderController', 'source' => ['file' => 'tests/Feature/OrderControllerTest.php']],
            ['id' => 'tests:tests/Unit/JobsTest.php', 'file' => 'tests/Unit/JobsTest.php', 'subject' => 'App\\Jobs', 'source' => ['file' => 'tests/Unit/JobsTest.php']],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 */
function affectedTestsOutput(array $parameters): string
{
    Artisan::call('necromancer:affected-tests', $parameters);

    return Artisan::output();
}

/**
 * Runs the command with $stdin as its input stream, capturing stdout and
 * stderr separately.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{int, string, string}
 */
function affectedTestsFromStdin(string $stdin, array $parameters = []): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput(['--stdin' => true, ...$parameters]);
    $input->setStream($stream);

    $output = new class extends BufferedOutput implements ConsoleOutputInterface
    {
        private BufferedOutput $stderr;

        public function getErrorOutput(): OutputInterface
        {
            return $this->stderr ??= new BufferedOutput;
        }

        public function setErrorOutput(OutputInterface $error): void {}

        public function section(): ConsoleSectionOutput
        {
            throw new LogicException('Not supported.');
        }
    };

    $command = app(AffectedTestsCommand::class);
    $command->setLaravel(app());
    $status = $command->run($input, $output);

    return [$status, $output->fetch(), $output->getErrorOutput()->fetch()];
}

beforeEach(fn () => File::delete(base_path('necromancer.json')));
afterEach(fn () => File::delete(base_path('necromancer.json')));

test('a model lists its own test as directly affected and its policy test as indirectly affected', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    expect(affectedTestsOutput(['artifact' => 'models:App\\Models\\Order']))->toBe(<<<'TEXT'
        Affected tests for models:App\Models\Order, depth 2

        Directly affected
          tests/Unit/OrderTest.php  ← via App\Models\Order

        Indirectly affected
          tests/Unit/OrderPolicyTest.php  ← via App\Policies\OrderPolicy

        TEXT);
});

test('--depth=1 drops the indirectly affected tier, and --depth=0 fails', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    expect(affectedTestsOutput(['artifact' => 'models:App\\Models\\Order', '--depth' => 1]))
        ->toContain('tests/Unit/OrderTest.php')
        ->not->toContain('Indirectly affected');

    $this->artisan('necromancer:affected-tests', ['artifact' => 'models:App\\Models\\Order', '--depth' => '0'])
        ->expectsOutputToContain('--depth option must be an integer of at least 1')
        ->assertFailed();
});

test('a fully-qualified class name resolves, an ambiguous one lists candidates, and an unknown one fails', function () {
    writeAffectedTestsManifest([
        ...affectedTestsCommandArtifacts(),
        'middleware' => [
            ['id' => 'middleware:alias:auth', 'alias' => 'auth', 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'alias', 'group' => null],
            ['id' => 'middleware:group:web:App\\Http\\Middleware\\Authenticate', 'alias' => null, 'class' => 'App\\Http\\Middleware\\Authenticate', 'scope' => 'group', 'group' => 'web'],
        ],
    ]);

    expect(affectedTestsOutput(['artifact' => 'App\\Models\\Order']))
        ->toContain('Affected tests for models:App\\Models\\Order, depth 2');

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Http\\Middleware\\Authenticate'])
        ->expectsOutputToContain('matches several artifacts')
        ->expectsOutputToContain('middleware:alias:auth')
        ->assertFailed();

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Ghost'])
        ->expectsOutputToContain("No artifact matches 'App\\Models\\Ghost'")
        ->assertFailed();
});

test('a flow start lists the tests of its members', function () {
    $artifacts = affectedTestsCommandArtifacts();
    $artifacts['jobs'][0]['annotations'] = ['flow' => 'shipping'];
    writeAffectedTestsManifest($artifacts);

    expect(affectedTestsOutput(['artifact' => 'flow:shipping']))->toBe(<<<'TEXT'
        Affected tests for flow:shipping, depth 2

        Indirectly affected
          tests/Unit/JobsTest.php  ← via App\Jobs\ShipOrder  (namespace match)

        TEXT);
});

test('--stdin maps changed files to artifacts and reports unmapped paths', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());
    $base = app()->basePath();

    [$status, $stdout] = affectedTestsFromStdin("./app/Models/Order.php\n{$base}/app/Models/Order.php\napp/Http/Controllers/OrderController.php\ntests/Unit/JobsTest.php\nroutes/web.php\n\n", ['--depth' => '1']);

    expect($status)->toBe(0)
        ->and($stdout)->toBe(<<<'TEXT'
            Affected tests for 4 changed artifact(s), depth 1

            Directly affected
              tests/Unit/JobsTest.php  (changed)
              tests/Unit/OrderTest.php  ← via App\Models\Order
              tests/Feature/OrderControllerTest.php  ← via App\Http\Controllers\OrderController

            Unmapped
              routes/web.php

            TEXT);
});

test('a test reached from two changed artifacts appears once, at its smallest distance', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    [, $stdout] = affectedTestsFromStdin("app/Models/Order.php\napp/Policies/OrderPolicy.php\n", ['--json' => true]);
    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

    expect(array_column($decoded['directly_affected'], 'file'))->toBe(['tests/Unit/OrderTest.php', 'tests/Unit/OrderPolicyTest.php'])
        ->and($decoded['indirectly_affected'])->toBe([]);
});

test('--json outputs the documented shape with namespace matches', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    $decoded = json_decode(affectedTestsOutput(['artifact' => 'jobs:App\\Jobs\\ShipOrder', '--json' => true]), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBe([
        'directly_affected' => [[
            'file' => 'tests/Unit/JobsTest.php',
            'id' => 'tests:tests/Unit/JobsTest.php',
            'distance' => 1,
            'match' => 'namespace',
            'start' => 'jobs:App\\Jobs\\ShipOrder',
            'via' => [
                'from' => 'jobs:App\\Jobs\\ShipOrder',
                'relationship' => [
                    'from' => 'jobs:App\\Jobs\\ShipOrder',
                    'type' => 'tested_by',
                    'to' => 'tests:tests/Unit/JobsTest.php',
                    'provenance' => ['source'],
                    'resolved' => true,
                    'metadata' => ['match' => 'namespace'],
                ],
                'direction' => 'out',
            ],
        ]],
        'indirectly_affected' => [],
        'unmapped' => [],
    ]);
});

test('--paths writes only test paths to stdout, and unmapped paths to stderr', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    [$status, $stdout, $stderr] = affectedTestsFromStdin("app/Models/Order.php\nroutes/web.php\n", ['--paths' => true]);

    expect($status)->toBe(0)
        ->and($stdout)->toBe("tests/Unit/OrderTest.php\ntests/Unit/OrderPolicyTest.php\n")
        ->and($stderr)->toBe("Unmapped: routes/web.php\n");
});

test('--json with --paths fails', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order', '--json' => true, '--paths' => true])
        ->expectsOutputToContain('mutually exclusive')
        ->assertFailed();
});

test('giving both the artifact and --stdin, or neither, fails', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order', '--stdin' => true])
        ->expectsOutputToContain('Pass exactly one of the artifact argument or --stdin.')
        ->assertFailed();

    $this->artisan('necromancer:affected-tests')
        ->expectsOutputToContain('Pass exactly one of the artifact argument or --stdin.')
        ->assertFailed();
});

test('a missing manifest fails', function () {
    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order'])
        ->expectsOutputToContain('Necromancer manifest not found. Run necromancer:scan first.')
        ->assertFailed();
});

test('a stale manifest is refused unless --allow-stale is passed, even with --stdin', function () {
    File::ensureDirectoryExists(base_path('app'));
    File::put(base_path('app/Placeholder.php'), '<?php');
    File::put(base_path('necromancer.json'), json_encode([
        'meta' => ['manifest_schema_version' => 1, 'generated_at' => '1970-01-01T00:00:00+00:00', 'scope' => ['complete' => true, 'artifact_types' => []]],
        'artifacts' => affectedTestsCommandArtifacts(),
    ], JSON_THROW_ON_ERROR));

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order'])
        ->expectsOutputToContain('may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to analyze anyway.')
        ->assertFailed();

    expect(affectedTestsFromStdin("app/Models/Order.php\n")[0])->toBe(1)
        ->and(affectedTestsFromStdin("app/Models/Order.php\n", ['--allow-stale' => true])[0])->toBe(0);

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order', '--allow-stale' => true])
        ->assertSuccessful();

    File::deleteDirectory(base_path('app'));
});

test('a partial-scope manifest is refused unless --allow-partial is passed', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts(), complete: false);

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order'])
        ->expectsOutputToContain('scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to analyze anyway.')
        ->assertFailed();

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Models\\Order', '--allow-partial' => true])
        ->assertSuccessful();
});

test('no affected tests exits 0 with an explicit message', function () {
    writeAffectedTestsManifest([...affectedTestsCommandArtifacts(), 'enums' => [['id' => 'enums:App\\Enums\\Status', 'class' => 'App\\Enums\\Status']]]);

    expect(affectedTestsOutput(['artifact' => 'App\\Enums\\Status']))->toContain('No affected tests found.');

    $this->artisan('necromancer:affected-tests', ['artifact' => 'App\\Enums\\Status'])->assertSuccessful();
});

test('an unchanged manifest and input yield byte-identical output', function () {
    writeAffectedTestsManifest(affectedTestsCommandArtifacts());
    $parameters = ['artifact' => 'App\\Models\\Order', '--depth' => 3];

    expect(affectedTestsOutput($parameters))->toBe(affectedTestsOutput($parameters))
        ->and(affectedTestsOutput([...$parameters, '--json' => true]))->toBe(affectedTestsOutput([...$parameters, '--json' => true]));
});
