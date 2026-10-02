<?php

use LaravelNecromancer\Benchmark\TaskSuite;
use LaravelNecromancer\Benchmark\TaskSuiteGenerator;
use LaravelNecromancer\Benchmark\TaskSuiteWriter;

$manifest = fn (): array => [
    'artifacts' => [
        'routes' => [
            ['name' => 'issues.index', 'method' => 'GET', 'uri' => '/issues', 'middleware' => ['auth']],
            ['name' => 'about', 'method' => 'GET', 'uri' => '/about', 'middleware' => []],
        ],
        'models' => [
            ['class' => 'App\\Models\\Issue', 'observers' => ['App\\Observers\\IssueObserver'], 'casts' => ['status' => 'string'], 'fillable' => ['title']],
            ['class' => 'App\\Models\\Project', 'observers' => [], 'casts' => [], 'fillable' => ['name']],
        ],
        'jobs' => [
            ['class' => 'App\\Jobs\\ArchiveIssues', 'queue' => 'default', 'tries' => 3, 'timeout' => 60],
        ],
        'events' => [
            ['class' => 'App\\Events\\IssueOpened'],
        ],
        'policies' => [
            ['model' => 'App\\Models\\Issue', 'policy' => 'App\\Policies\\IssuePolicy'],
        ],
    ],
];

it('generates 14 tasks', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    expect($tasks)->toHaveCount(14);
});

it('generates all expected task ids', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $ids = array_column($tasks, 'id');
    expect($ids)->toContain('qa-001', 'qa-002', 'qa-003', 'qa-004', 'qa-005', 'qa-006', 'qa-007');
    expect($ids)->toContain('codegen-001', 'codegen-002', 'codegen-003', 'codegen-004');
    expect($ids)->toContain('mini-001', 'mini-002', 'mini-003');
});

it('grounds qa-002 to the first model with an observer', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $qa002 = collect($tasks)->firstWhere('id', 'qa-002');
    expect($qa002['prompt'])->toContain('Issue');
    expect($qa002['assertions']['must_recall_from'])->toBe('models.observer_short_names.Issue');
    expect($qa002['required_key'])->toBe('models.observer_short_names.Issue');
});

it('grounds qa-004 to the first model with casts', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $qa004 = collect($tasks)->firstWhere('id', 'qa-004');
    expect($qa004['prompt'])->toContain('Issue');
    expect($qa004['assertions']['must_recall_from'])->toBe('models.cast_keys.Issue');
    expect($qa004['required_key'])->toBe('models.cast_keys.Issue');
});

it('grounds codegen-004 to the first event', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $codegen004 = collect($tasks)->firstWhere('id', 'codegen-004');
    expect($codegen004['prompt'])->toContain('IssueOpened');
    expect($codegen004['assertions']['must_contain'])->toContain('IssueOpened');
});

it('grounds mini-002 to the first event', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $mini002 = collect($tasks)->firstWhere('id', 'mini-002');
    expect($mini002['prompt'])->toContain('IssueOpened');
    expect($mini002['assertions']['must_contain'])->toContain('IssueOpened');
});

it('includes job-specific fact_keys when jobs are present', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $qa003 = collect($tasks)->firstWhere('id', 'qa-003');
    expect($qa003['assertions']['fact_keys'])->toContain('jobs.queue.ArchiveIssues');
    expect($qa003['assertions']['fact_keys'])->toContain('jobs.tries.ArchiveIssues');
});

it('falls back to generic qa-002 when no model has observers', function () {
    $empty = ['artifacts' => ['routes' => [], 'models' => [], 'jobs' => [], 'events' => [], 'policies' => []]];
    $tasks = (new TaskSuiteGenerator($empty))->generate();
    $qa002 = collect($tasks)->firstWhere('id', 'qa-002');
    expect($qa002['required_key'])->toBe('models.with_observers');
    expect($qa002['prompt'])->not->toContain('Issue');
});

it('falls back to generic codegen-004 when no events exist', function () {
    $empty = ['artifacts' => ['routes' => [], 'models' => [], 'jobs' => [], 'events' => [], 'policies' => []]];
    $tasks = (new TaskSuiteGenerator($empty))->generate();
    $codegen004 = collect($tasks)->firstWhere('id', 'codegen-004');
    expect($codegen004['assertions'])->toHaveKey('must_recall_from');
});

it('sets conditions to [none, manual, necromancer-mcp, necromancer-mcp-graph] on all Q&A tasks', function () use ($manifest) {
    $tasks = [
        ...(new TaskSuiteGenerator($manifest()))->generate(),
        ...(new TaskSuiteGenerator(impactGeneratorManifest()))->generate(),
    ];
    $qaTasks = array_filter($tasks, fn ($t) => $t['type'] === 'qa');

    foreach ($qaTasks as $task) {
        expect($task['conditions'])->toBe(['none', 'manual', 'necromancer-mcp', 'necromancer-mcp-graph']);
    }
});

it('does not set conditions on codegen or mini tasks', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $nonQa = array_filter($tasks, fn ($t) => $t['type'] !== 'qa');

    foreach ($nonQa as $task) {
        expect($task)->not->toHaveKey('conditions');
    }
});

it('renders conditions field in the PHP output', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $output = (new TaskSuiteWriter)->render($tasks, '2026-06-08');
    expect($output)->toContain("'conditions'");
    expect($output)->toContain("'none'");
    expect($output)->toContain("'manual'");
});

it('renders a valid PHP file', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $output = (new TaskSuiteWriter)->render($tasks, '2026-06-08');
    expect($output)->toStartWith('<?php');
    expect($output)->toContain("'id'");
    expect($output)->toContain("'qa-001'");
    expect($output)->toContain('return [');
    expect($output)->toContain('2026-06-08');
});

it('rendered file can be required and returns 14 tasks', function () use ($manifest) {
    $tasks = (new TaskSuiteGenerator($manifest()))->generate();
    $output = (new TaskSuiteWriter)->render($tasks, '2026-06-08');

    $tmpFile = sys_get_temp_dir().'/necromancer-test-suite-'.uniqid().'.php';
    file_put_contents($tmpFile, $output);
    $loaded = require $tmpFile;
    @unlink($tmpFile);

    expect($loaded)->toBeArray()->toHaveCount(14);
});

/**
 * Order and Invoice take part in three Relationships each (Order:
 * relates_to User, authorized_by OrderPolicy, Invoice's relates_to Order;
 * Invoice: that relates_to, authorized_by InvoicePolicy, belongs_to_flow
 * billing), so the tie breaks to Order, the earlier one in manifest
 * order. User only takes part in Order's relates_to.
 */
function impactGeneratorManifest(): array
{
    return ['artifacts' => [
        'models' => [
            ['id' => 'models:App\\Models\\User', 'class' => 'App\\Models\\User'],
            [
                'id' => 'models:App\\Models\\Order',
                'class' => 'App\\Models\\Order',
                'policy' => 'App\\Policies\\OrderPolicy',
                'relationships' => [['type' => 'belongsTo', 'related' => 'App\\Models\\User', 'method' => 'customer']],
            ],
            [
                'id' => 'models:App\\Models\\Invoice',
                'class' => 'App\\Models\\Invoice',
                'policy' => 'App\\Policies\\InvoicePolicy',
                'relationships' => [['type' => 'belongsTo', 'related' => 'App\\Models\\Order', 'method' => 'order']],
                'annotations' => ['flow' => 'billing'],
            ],
        ],
        'policies' => [
            ['id' => 'policies:App\\Policies\\OrderPolicy', 'class' => 'App\\Policies\\OrderPolicy'],
            ['id' => 'policies:App\\Policies\\InvoicePolicy', 'class' => 'App\\Policies\\InvoicePolicy'],
        ],
    ]];
}

it('generates the listener and dispatch Q&A tasks of the bundled suite', function () use ($manifest) {
    $tasks = collect((new TaskSuiteGenerator($manifest()))->generate())->keyBy('id');

    expect($tasks['qa-006'])->toBe((new TaskSuite)->tasks()[5])
        ->and($tasks['qa-007'])->toBe((new TaskSuite)->tasks()[6]);
});

it('generates an impact task for the model with the most Relationships, breaking ties by manifest order', function () {
    $task = collect((new TaskSuiteGenerator(impactGeneratorManifest()))->generate())->firstWhere('id', 'qa-008');

    expect($task)->toBe([
        'id' => 'qa-008',
        'type' => 'qa',
        'prompt' => 'What is directly connected to the Order model?',
        'required_key' => 'impact.labels.models:App\\Models\\Order',
        'conditions' => ['none', 'manual', 'necromancer-mcp', 'necromancer-mcp-graph'],
        'assertions' => [
            'must_recall_from' => 'impact.labels.models:App\\Models\\Order',
            'fact_keys' => ['impact.labels.models:App\\Models\\Order'],
        ],
    ]);
});

it('omits the impact task when no model has a Relationship', function () use ($manifest) {
    $ids = array_column((new TaskSuiteGenerator($manifest()))->generate(), 'id');

    expect($ids)->not->toContain('qa-008');
});

it('renders the impact task so it loads back with its Artifact ID key intact', function () {
    $tasks = (new TaskSuiteGenerator(impactGeneratorManifest()))->generate();

    $tmpFile = sys_get_temp_dir().'/necromancer-test-suite-'.uniqid().'.php';
    file_put_contents($tmpFile, (new TaskSuiteWriter)->render($tasks, '2026-06-08'));
    $loaded = require $tmpFile;
    @unlink($tmpFile);

    expect(collect($loaded)->firstWhere('id', 'qa-008'))->toBe(collect($tasks)->firstWhere('id', 'qa-008'));
});
