<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use LaravelNecromancer\Benchmark\GenerationAgent;
use LaravelNecromancer\Benchmark\Tools\QueryArtifactsTool as BenchmarkQueryArtifactsTool;
use LaravelNecromancer\Benchmark\Tools\QueryModelsTool as BenchmarkQueryModelsTool;
use LaravelNecromancer\Benchmark\Tools\QueryRoutesTool as BenchmarkQueryRoutesTool;
use LaravelNecromancer\Benchmark\Tools\SearchArtifactsTool as BenchmarkSearchArtifactsTool;
use LaravelNecromancer\Inference\TemperatureAwareStructuredAgent;
use LaravelNecromancer\Integrations\AiDetector;

function fakeJudgeResponses(int $count = 20): array
{
    return array_fill(0, $count, [
        'correctness' => 3,
        'completeness' => 3,
        'conventions' => 2,
        'conciseness' => 2,
        'total' => 10,
    ]);
}

function benchmarkManifest(): string
{
    return json_encode([
        'meta' => ['manifest_schema_version' => 1,
            'app_name' => 'TestApp',
            'generated_at' => now()->toISOString(),
            'content_hash' => 'abc123',
            'laravel_version' => '13.0',
            'php_version' => '8.4',
        ],
        'artifacts' => [
            'routes' => [
                ['name' => 'projects.index', 'method' => 'GET', 'uri' => '/projects', 'middleware' => ['auth'], 'controller' => 'ProjectController', 'action' => 'index', 'source' => null],
            ],
            'models' => [],
            'jobs' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

function benchmarkAiAvailable(): AiDetector
{
    return new AiDetector(ServiceProvider::class);
}

function benchmarkDumpDirectories(string $path): array
{
    return array_values(File::directories($path));
}

function benchmarkDumpDirectory(string $path): string
{
    $directories = benchmarkDumpDirectories($path);

    expect($directories)->toHaveCount(1);

    return $directories[0];
}

beforeEach(function () {
    $this->app->register(AiServiceProvider::class);
    $this->instance(AiDetector::class, benchmarkAiAvailable());

    GenerationAgent::fake(['The route projects.index requires auth middleware.']);

    $this->benchmarkDumpPath = storage_path('framework/testing/necromancer-benchmark-dumps');

    File::delete($this->benchmarkDumpPath);
    File::deleteDirectory($this->benchmarkDumpPath);
    File::delete(storage_path('framework/testing/manual-benchmark-context.md'));

    config([
        'necromancer.benchmark.dump.enabled' => true,
        'necromancer.benchmark.dump.path' => $this->benchmarkDumpPath,
    ]);

    File::delete(base_path('necromancer.json'));
    File::delete(base_path('benchmark-test-output.md'));
});

afterEach(function () {
    File::delete(base_path('necromancer.json'));
    File::delete(base_path('benchmark-test-output.md'));
    File::delete($this->benchmarkDumpPath);
    File::deleteDirectory($this->benchmarkDumpPath);
    File::delete(storage_path('framework/testing/manual-benchmark-context.md'));
});

test('the benchmark command is registered in artisan', function () {
    $this->artisan('list')
        ->expectsOutputToContain('necromancer:benchmark')
        ->assertSuccessful();
});

test('fails when the manifest does not exist', function () {
    $this->artisan('necromancer:benchmark')
        ->expectsOutputToContain('necromancer:scan')
        ->assertFailed();
});

test('fails when laravel/ai is not installed', function () {
    $this->instance(AiDetector::class, new AiDetector('NonExistent\\AiProvider'));
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark')
        ->expectsOutputToContain('laravel/ai')
        ->assertFailed();
});

test('runs successfully with --no-judge and --condition=none on a minimal task suite', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();
});

test('outputs results table in terminal format by default', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])
        ->expectsOutputToContain('Benchmark')
        ->expectsOutputToContain('No context')
        ->expectsOutputToContain('Report')
        ->expectsOutputToContain('Dump')
        ->doesntExpectOutputToContain('Output')
        ->doesntExpectOutputToContain('Judge Latency')
        ->assertSuccessful();
});

test('shows latency and judge latency columns in terminal output when the judge runs', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));
    TemperatureAwareStructuredAgent::fake(fakeJudgeResponses());

    // "Judge Latency" contains "Latency" as a substring, so this single assertion covers both
    // columns without tripping Laravel's expectsOutputToContain quirk, where multiple
    // substrings landing in the same underlying console write only credit the first match.
    $this->artisan('necromancer:benchmark', [
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])
        ->expectsOutputToContain('Judge Latency')
        ->assertSuccessful();
});

test('includes latency and judge latency columns in markdown output when the judge runs', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));
    TemperatureAwareStructuredAgent::fake(fakeJudgeResponses());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $markdown = File::get($outputPath);

    expect($markdown)->toContain('Latency')
        ->and($markdown)->toContain('Judge Latency');
});

test('omits the judge latency column from markdown output when no judge data exists', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $markdown = File::get($outputPath);

    expect($markdown)->toContain('Latency')
        ->and($markdown)->not->toContain('Judge Latency');
});

test('writes markdown report when --format=markdown --output is given', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    expect(File::exists($outputPath))->toBeTrue()
        ->and(File::get($outputPath))->toContain('# Necromancer Benchmark Results');
});

test('writes json report when --format=json --output is given', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);

    expect($json)->toHaveKeys(['summary', 'results'])
        ->and($json['results'])->not->toBeEmpty();
});

test('json report results include raw latency fields, null judge latency when the judge did not run', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $firstResult = $json['results'][0];

    expect($firstResult)->toHaveKeys(['latency_ms', 'judge_latency_ms'])
        ->and($firstResult['latency_ms'])->toBeInt()
        ->and($firstResult['latency_ms'])->toBeGreaterThanOrEqual(0)
        ->and($firstResult['judge_latency_ms'])->toBeNull();
});

test('json report includes latency fields in results and summary when the judge runs', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));
    TemperatureAwareStructuredAgent::fake(fakeJudgeResponses());

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $firstResult = $json['results'][0];

    expect($firstResult['judge_latency_ms'])->toBeInt()
        ->and($firstResult['judge_latency_ms'])->toBeGreaterThanOrEqual(0)
        ->and($json['summary']['none'])->toHaveKeys([
            'avgLatencyMs', 'latencyStdDevMs', 'avgJudgeLatencyMs', 'judgeLatencyStdDevMs',
        ]);
});

test('accepts --timeout option without failing', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--timeout' => '180',
    ])->assertSuccessful();
});

test('writes an automatic benchmark dump directory for each successful run', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])
        ->expectsOutputToContain('Dump written to')
        ->assertSuccessful();

    $dumpDirectory = benchmarkDumpDirectory($this->benchmarkDumpPath);

    expect(basename($dumpDirectory))->toMatch('/^\d{4}-\d{2}-\d{2}-\d{6}/')
        ->and(File::exists($dumpDirectory.'/run.json'))->toBeTrue()
        ->and(File::exists($dumpDirectory.'/results.json'))->toBeTrue()
        ->and(File::isDirectory($dumpDirectory.'/responses'))->toBeTrue()
        ->and(File::files($dumpDirectory.'/responses'))->not->toBeEmpty();
});

test('benchmark dump results include prompts and generated responses', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();

    $dumpDirectory = benchmarkDumpDirectory($this->benchmarkDumpPath);
    $results = json_decode(File::get($dumpDirectory.'/results.json'), true, 512, JSON_THROW_ON_ERROR);
    $firstResult = $results['results'][0];

    expect($firstResult['prompt'])->not->toBeEmpty()
        ->and($firstResult['response'])->toContain('projects.index')
        ->and(File::get(File::files($dumpDirectory.'/responses')[0]->getPathname()))->toContain('## Prompt')
        ->and(File::get(File::files($dumpDirectory.'/responses')[0]->getPathname()))->toContain('## Response');
});

test('benchmark dump results include raw per-task latency fields', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();

    $dumpDirectory = benchmarkDumpDirectory($this->benchmarkDumpPath);
    $results = json_decode(File::get($dumpDirectory.'/results.json'), true, 512, JSON_THROW_ON_ERROR);
    $firstResult = $results['results'][0];

    expect($firstResult)->toHaveKeys(['latency_ms', 'judge_latency_ms'])
        ->and($firstResult['latency_ms'])->toBeInt()
        ->and($firstResult['latency_ms'])->toBeGreaterThanOrEqual(0)
        ->and($firstResult['judge_latency_ms'])->toBeNull()
        ->and(File::get(File::files($dumpDirectory.'/responses')[0]->getPathname()))->toContain('Latency:');
});

test('benchmark dump records context metadata without copying full context text', function () {
    $manualContextPath = storage_path('framework/testing/manual-benchmark-context.md');

    File::put(base_path('necromancer.json'), benchmarkManifest());
    File::put($manualContextPath, 'SECRET_FULL_CONTEXT_SENTINEL');

    config(['necromancer.benchmark.manual_context_path' => $manualContextPath]);

    GenerationAgent::fake(array_fill(0, 20, 'The route projects.index requires auth middleware.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['manual'],
        '--type' => ['qa'],
    ])->assertSuccessful();

    $dumpDirectory = benchmarkDumpDirectory($this->benchmarkDumpPath);
    $runJson = File::get($dumpDirectory.'/run.json');
    $run = json_decode($runJson, true, 512, JSON_THROW_ON_ERROR);

    expect($run['contexts']['manual'])->toMatchArray([
        'path' => $manualContextPath,
        'exists' => true,
        'bytes' => strlen('SECRET_FULL_CONTEXT_SENTINEL'),
        'sha256' => hash('sha256', 'SECRET_FULL_CONTEXT_SENTINEL'),
    ])->and($runJson)->not->toContain('SECRET_FULL_CONTEXT_SENTINEL');

    File::delete($manualContextPath);
});

test('--no-dump prevents benchmark dump creation', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--no-dump' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])
        ->doesntExpectOutputToContain('Dump written to')
        ->assertSuccessful();

    expect(File::exists($this->benchmarkDumpPath))->toBeFalse();
});

test('fails clearly when the benchmark dump path cannot be created as a directory', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    File::put($this->benchmarkDumpPath, 'not a directory');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])
        ->expectsOutputToContain('Unable to write benchmark dump')
        ->assertFailed();
});

test('task is skipped and reported when required_key resolves to null', function () {
    $manifest = json_encode([
        'meta' => ['manifest_schema_version' => 1, 'app_name' => 'T', 'generated_at' => now()->toISOString(), 'content_hash' => 'x', 'laravel_version' => '13', 'php_version' => '8.4'],
        'artifacts' => ['routes' => [], 'models' => [], 'jobs' => [], 'events' => [], 'policies' => []],
    ], JSON_THROW_ON_ERROR);

    File::put(base_path('necromancer.json'), $manifest);

    $outputPath = base_path('benchmark-skip-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);

    $skippedResults = array_filter($json['results'], fn ($r) => $r['skipped'] === true);
    expect($skippedResults)->not->toBeEmpty();
    expect($json['summary'])->toBe([]);

    File::delete($outputPath);
});

test('task is skipped when required_key resolves to an empty list', function () {
    $manifest = json_encode([
        'meta' => ['manifest_schema_version' => 1, 'app_name' => 'T', 'generated_at' => now()->toISOString(), 'content_hash' => 'x', 'laravel_version' => '13', 'php_version' => '8.4'],
        'artifacts' => [
            'routes' => [['name' => 'welcome', 'method' => 'GET', 'uri' => '/', 'middleware' => [], 'controller' => null, 'action' => null, 'source' => null]],
            'models' => [], 'jobs' => [], 'events' => [], 'policies' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    File::put(base_path('necromancer.json'), $manifest);

    $outputPath = base_path('benchmark-skip-empty-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $skipped = array_filter($json['results'], fn ($r) => $r['skipped'] === true);
    expect(count($skipped))->toBe(count($json['results']));

    File::delete($outputPath);
});

test('resolves necromancer context to skill_path config when Boost is available', function () {
    // Point skill_path to a known directory, guidelines to a different file.
    // The benchmark should load from {skill_path}/SKILL.md, not context_path/guidelines.
    $skillPath = base_path('_bench_skill_test');
    $guidelinesPath = base_path('_bench_guidelines_test.md');

    File::ensureDirectoryExists($skillPath);
    File::put($skillPath.'/SKILL.md', 'SKILL_SENTINEL');
    File::put($guidelinesPath, 'GUIDELINES_SENTINEL');

    config([
        'necromancer.boost.skill_path' => $skillPath,
        'necromancer.boost.context_path' => $guidelinesPath,
    ]);

    File::put(base_path('necromancer.json'), benchmarkManifest());

    GenerationAgent::fake(['ok']);

    $outputPath = base_path('_bench_skill_test_out.json');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer'],
        '--type' => ['codegen'],
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $ran = array_filter($json['results'], fn ($r) => ! $r['skipped']);

    // At least one non-skipped codegen task means the skill file was found and loaded.
    expect(count($ran))->toBeGreaterThan(0);

    File::deleteDirectory($skillPath);
    File::delete([$guidelinesPath, $outputPath]);
});

test('only runs tasks matching --type filter', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    GenerationAgent::fake(array_fill(0, 5, 'The route projects.index requires auth.'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['qa'],
    ])->assertSuccessful();
});

test('Q&A tasks are skipped for the necromancer condition', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-qa-conditions-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer'],
        '--type' => ['qa'],
        '--no-dump' => true,
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $skipped = array_filter($json['results'], fn ($r) => $r['skipped'] === true);

    expect(count($skipped))->toBe(count($json['results']));

    File::delete($outputPath);
});

test('codegen tasks run for all three conditions', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $outputPath = base_path('benchmark-codegen-conditions-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none', 'manual', 'necromancer'],
        '--type' => ['codegen'],
        '--no-dump' => true,
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $notSkipped = array_filter($json['results'], fn ($r) => $r['skipped'] === false);

    // At least one codegen task ran for each condition
    $conditions = array_unique(array_column($notSkipped, 'condition'));
    expect($conditions)->toContain('none', 'necromancer');

    File::delete($outputPath);
});

test('accepts --condition=necromancer-mcp and runs successfully', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer-mcp'],
        '--type' => ['codegen'],
    ])->assertSuccessful();
});

test('necromancer-mcp is NOT included by default when --condition is omitted entirely', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $outputPath = base_path('benchmark-default-conditions-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--type' => ['codegen'],
        '--no-dump' => true,
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $conditions = array_unique(array_column($json['results'], 'condition'));

    expect($conditions)->toContain('none', 'manual', 'necromancer')
        ->not->toContain('necromancer-mcp');

    File::delete($outputPath);
});

test('necromancer-mcp condition builds the generation agent with bare instructions and the four benchmark tools attached', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(['auth authorize Route::get']);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer-mcp'],
        '--type' => ['codegen'],
    ])->assertSuccessful();

    GenerationAgent::assertPrompted(function (AgentPrompt $prompt): bool {
        $tools = iterator_to_array($prompt->agent->tools());
        $toolClasses = array_map(get_class(...), $tools);

        return $prompt->agent->instructions() === 'You are a Laravel expert. Answer questions about this codebase accurately and concisely.'
            && count($tools) === 4
            && in_array(BenchmarkQueryRoutesTool::class, $toolClasses, true)
            && in_array(BenchmarkQueryModelsTool::class, $toolClasses, true)
            && in_array(BenchmarkQueryArtifactsTool::class, $toolClasses, true)
            && in_array(BenchmarkSearchArtifactsTool::class, $toolClasses, true);
    });
});

test('the none condition still builds the generation agent with no tools attached', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(['auth authorize Route::get']);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none'],
        '--type' => ['codegen'],
    ])->assertSuccessful();

    GenerationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => iterator_to_array($prompt->agent->tools()) === []);
});

test('Q&A tasks run (are not skipped) under the necromancer-mcp condition', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = base_path('benchmark-qa-mcp-conditions-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer-mcp'],
        '--type' => ['qa'],
        '--no-dump' => true,
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);
    $notSkipped = array_filter($json['results'], fn ($r) => $r['skipped'] === false);

    expect($notSkipped)->not->toBeEmpty();

    File::delete($outputPath);
});

test('necromancer-mcp condition produces a report entry with latency and token metrics', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 5, 'auth authorize Route::get'));

    $outputPath = base_path('benchmark-mcp-metrics-test.json');
    File::delete($outputPath);

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer-mcp'],
        '--type' => ['codegen'],
        '--no-dump' => true,
        '--format' => 'json',
        '--output' => $outputPath,
    ])->assertSuccessful();

    $json = json_decode(File::get($outputPath), true);

    expect($json['summary'])->toHaveKey('necromancer-mcp')
        ->and($json['summary']['necromancer-mcp'])->toHaveKeys(['avgLatencyMs', 'avgPromptTokens', 'avgCompletionTokens']);

    $mcpResult = collect($json['results'])->first(fn ($r) => $r['condition'] === 'necromancer-mcp' && $r['skipped'] === false);
    expect($mcpResult)->not->toBeNull()
        ->and($mcpResult['latency_ms'])->toBeInt()
        ->and($mcpResult['latency_ms'])->toBeGreaterThanOrEqual(0);

    File::delete($outputPath);
});

test('terminal output shows the Necromancer (MCP) vs Necromancer (static) comparison line when both conditions are present', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer', 'necromancer-mcp'],
        '--type' => ['codegen'],
    ])
        ->expectsOutputToContain('Necromancer (MCP) vs Necromancer (static):')
        ->assertSuccessful();
});

test('terminal output omits the Necromancer (MCP) vs Necromancer (static) line when necromancer-mcp is absent', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none', 'manual', 'necromancer'],
        '--type' => ['codegen'],
    ])
        ->doesntExpectOutputToContain('Necromancer (MCP) vs Necromancer (static)')
        ->assertSuccessful();
});

test('terminal output omits the Necromancer (MCP) vs Necromancer (static) line when necromancer is absent', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none', 'necromancer-mcp'],
        '--type' => ['codegen'],
    ])
        ->doesntExpectOutputToContain('Necromancer (MCP) vs Necromancer (static)')
        ->assertSuccessful();
});

test('markdown output includes the Necromancer (MCP) vs Necromancer (static) comparison line when both conditions are present', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['necromancer', 'necromancer-mcp'],
        '--type' => ['codegen'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    expect(File::get($outputPath))->toContain('**Necromancer (MCP) vs Necromancer (static):**');
});

test('markdown output omits the Necromancer (MCP) vs Necromancer (static) line when necromancer-mcp is absent', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none', 'manual', 'necromancer'],
        '--type' => ['codegen'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    expect(File::get($outputPath))->not->toContain('Necromancer (MCP) vs Necromancer (static)');
});

test('markdown output omits the Necromancer (MCP) vs Necromancer (static) line when necromancer is absent', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());
    GenerationAgent::fake(array_fill(0, 20, 'auth authorize Route::get'));

    $outputPath = base_path('benchmark-test-output.md');

    $this->artisan('necromancer:benchmark', [
        '--no-judge' => true,
        '--condition' => ['none', 'necromancer-mcp'],
        '--type' => ['codegen'],
        '--format' => 'markdown',
        '--output' => $outputPath,
    ])->assertSuccessful();

    expect(File::get($outputPath))->not->toContain('Necromancer (MCP) vs Necromancer (static)');
});

test('--generate-suite writes a PHP task file and exits without benchmarking', function () {
    File::put(base_path('necromancer.json'), benchmarkManifest());

    $outputPath = sys_get_temp_dir().'/necromancer-suite-'.uniqid().'.php';

    $this->artisan('necromancer:benchmark', [
        '--generate-suite' => true,
        '--suite-output' => $outputPath,
    ])
        ->expectsOutputToContain('Suite written to')
        ->expectsOutputToContain("'tasks' => require")
        ->assertSuccessful();

    expect(file_exists($outputPath))->toBeTrue();

    $tasks = require $outputPath;
    expect($tasks)->toBeArray()->toHaveCount(12);

    @unlink($outputPath);
});

test('--generate-suite generates tasks grounded to manifest artifacts', function () {
    $manifest = json_encode([
        'meta' => ['manifest_schema_version' => 1, 'app_name' => 'TestApp', 'generated_at' => now()->toISOString(), 'content_hash' => 'abc', 'laravel_version' => '13.0', 'php_version' => '8.4'],
        'artifacts' => [
            'routes' => [
                ['name' => 'orders.index', 'method' => 'GET', 'uri' => '/orders', 'middleware' => ['auth'], 'controller' => 'OrderController', 'action' => 'index', 'source' => null],
            ],
            'models' => [
                ['class' => 'App\\Models\\Order', 'observers' => ['App\\Observers\\OrderObserver'], 'casts' => ['total' => 'decimal:2'], 'fillable' => ['amount']],
            ],
            'jobs' => [],
            'events' => [['class' => 'App\\Events\\OrderPlaced']],
            'policies' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    File::put(base_path('necromancer.json'), $manifest);

    $outputPath = sys_get_temp_dir().'/necromancer-suite-grounded-'.uniqid().'.php';

    $this->artisan('necromancer:benchmark', [
        '--generate-suite' => true,
        '--suite-output' => $outputPath,
    ])->assertSuccessful();

    $content = File::get($outputPath);
    expect($content)->toContain('Order');
    expect($content)->toContain('OrderPlaced');

    @unlink($outputPath);
});
