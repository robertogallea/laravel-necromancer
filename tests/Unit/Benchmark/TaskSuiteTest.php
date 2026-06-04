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
