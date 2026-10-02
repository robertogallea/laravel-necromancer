<?php

use LaravelNecromancer\Benchmark\TaskSuite;

function stubTasks(): array
{
    return [
        ['id' => 'qa-001',      'type' => 'qa',      'prompt' => 'What routes require auth?',    'assertions' => ['must_contain' => [], 'must_not_contain' => [], 'fact_keys' => []]],
        ['id' => 'codegen-001', 'type' => 'codegen', 'prompt' => 'Add a route to archive issue',  'assertions' => ['must_contain' => [], 'must_not_contain' => [], 'fact_keys' => []]],
        ['id' => 'mini-001',    'type' => 'mini',    'prompt' => 'Implement close-all feature',    'assertions' => ['must_contain' => [], 'must_not_contain' => [], 'fact_keys' => []]],
    ];
}

test('tasks() returns all tasks when no type filter provided', function () {
    $suite = new TaskSuite(stubTasks());

    expect($suite->tasks())->toHaveCount(3);
});

test('tasks() filters by a single type', function () {
    $suite = new TaskSuite(stubTasks());

    expect($suite->tasks(['qa']))->toHaveCount(1)
        ->and($suite->tasks(['qa'])[0]['id'])->toBe('qa-001');
});

test('tasks() filters by multiple types', function () {
    $suite = new TaskSuite(stubTasks());

    expect($suite->tasks(['qa', 'codegen']))->toHaveCount(2);
});

test('tasks() returns empty array when type does not match', function () {
    $suite = new TaskSuite(stubTasks());

    expect($suite->tasks(['unknown']))->toBeEmpty();
});

test('tasks() returns numerically re-indexed array', function () {
    $suite = new TaskSuite(stubTasks());

    $result = $suite->tasks(['qa', 'mini']);

    expect(array_keys($result))->toBe([0, 1]);
});

test('every bundled Q&A task runs under both MCP conditions and never under static necromancer', function () {
    foreach ((new TaskSuite)->tasks(['qa']) as $task) {
        expect($task['conditions'])->toBe(['none', 'manual', 'necromancer-mcp', 'necromancer-mcp-graph']);
    }
});

test('the bundled suite asks which listener handles each event, scored on listener recall', function () {
    $task = collect((new TaskSuite)->tasks())->firstWhere('id', 'qa-006');

    expect($task)->toMatchArray([
        'type' => 'qa',
        'prompt' => 'Which events have listeners, and which listener handles each?',
        'required_key' => 'events.listeners',
        'conditions' => ['none', 'manual', 'necromancer-mcp', 'necromancer-mcp-graph'],
    ])->and($task['assertions']['must_recall_from'])->toBe('events.listeners')
        ->and($task['assertions']['fact_keys'])->toBe(['events.listeners']);
});

test('the bundled suite asks what each dispatching class dispatches, scored on target recall', function () {
    $task = collect((new TaskSuite)->tasks())->firstWhere('id', 'qa-007');

    expect($task)->toMatchArray([
        'type' => 'qa',
        'prompt' => 'Which classes dispatch jobs, events, or mailables, and what do they dispatch?',
        'required_key' => 'dispatches.targets',
        'conditions' => ['none', 'manual', 'necromancer-mcp', 'necromancer-mcp-graph'],
    ])->and($task['assertions']['must_recall_from'])->toBe('dispatches.targets')
        ->and($task['assertions']['fact_keys'])->toBe(['dispatches.targets']);
});
