<?php

use LaravelNecromancer\Benchmark\BenchmarkReport;
use LaravelNecromancer\Benchmark\BenchmarkResult;

function makeResult(string $condition, float $accuracy, float $hallucinationRate, ?float $judgeScore = null, int $promptTokens = 100, int $completionTokens = 50): BenchmarkResult
{
    return new BenchmarkResult(
        taskId: 'qa-001',
        taskType: 'qa',
        condition: $condition,
        prompt: 'test prompt',
        response: 'test response',
        promptTokens: $promptTokens,
        completionTokens: $completionTokens,
        accuracy: $accuracy,
        hallucinationRate: $hallucinationRate,
        judgeScore: $judgeScore,
        judgeTokens: $judgeScore !== null ? 80 : null,
        goldenAnswersTrusted: true,
    );
}

test('byCondition returns averaged accuracy per condition', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.4, 0.2),
        makeResult('none', 0.6, 0.1),
        makeResult('necromancer', 0.9, 0.0),
    ]);

    $by = $report->byCondition();

    expect($by['none']['accuracy'])->toBe(0.5)
        ->and($by['necromancer']['accuracy'])->toBe(0.9);
});

test('byCondition returns averaged hallucination rate per condition', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.5, 0.2),
        makeResult('none', 0.5, 0.0),
    ]);

    expect($report->byCondition()['none']['hallucinationRate'])->toBe(0.1);
});

test('byCondition averages judge scores only over judged results', function () {
    $report = new BenchmarkReport([
        makeResult('necromancer', 0.9, 0.0, 8.0),
        makeResult('necromancer', 0.9, 0.0, null),
    ]);

    expect($report->byCondition()['necromancer']['qualityScore'])->toBe(8.0);
});

test('byCondition averages token counts per condition', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.5, 0.1, null, 100, 50),
        makeResult('none', 0.5, 0.1, null, 200, 100),
    ]);

    $by = $report->byCondition();

    expect($by['none']['avgPromptTokens'])->toBe(150)
        ->and($by['none']['avgCompletionTokens'])->toBe(75);
});

test('byCondition returns empty array for no results', function () {
    expect((new BenchmarkReport([]))->byCondition())->toBe([]);
});
