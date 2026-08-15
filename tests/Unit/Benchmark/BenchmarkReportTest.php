<?php

use LaravelNecromancer\Benchmark\BenchmarkReport;
use LaravelNecromancer\Benchmark\BenchmarkResult;

function makeResult(string $condition, float $accuracy, float $hallucinationRate, ?float $judgeScore = null, int $promptTokens = 100, int $completionTokens = 50, int $latencyMs = 1000, ?int $judgeLatencyMs = null): BenchmarkResult
{
    return new BenchmarkResult(
        taskId: 'qa-001',
        taskType: 'qa',
        condition: $condition,
        prompt: 'test prompt',
        response: 'test response',
        promptTokens: $promptTokens,
        completionTokens: $completionTokens,
        latencyMs: $latencyMs,
        accuracy: $accuracy,
        hallucinationRate: $hallucinationRate,
        judgeScore: $judgeScore,
        judgeTokens: $judgeScore !== null ? 80 : null,
        judgeLatencyMs: $judgeLatencyMs ?? ($judgeScore !== null ? 500 : null),
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

test('byCondition excludes skipped results from averages', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.8, 0.1),
        new BenchmarkResult(
            taskId: 'qa-002', taskType: 'qa', condition: 'none',
            prompt: 'p', response: '', promptTokens: 0, completionTokens: 0, latencyMs: 0,
            accuracy: 0.0, hallucinationRate: 0.0,
            judgeScore: null, judgeTokens: null, judgeLatencyMs: null, goldenAnswersTrusted: false,
            skipped: true, skipReason: 'required key absent',
        ),
    ]);

    $by = $report->byCondition();

    expect($by['none']['accuracy'])->toBe(0.8);
});

test('byCondition omits condition entirely when all its results are skipped', function () {
    $report = new BenchmarkReport([
        new BenchmarkResult(
            taskId: 'qa-001', taskType: 'qa', condition: 'none',
            prompt: 'p', response: '', promptTokens: 0, completionTokens: 0, latencyMs: 0,
            accuracy: 0.0, hallucinationRate: 0.0,
            judgeScore: null, judgeTokens: null, judgeLatencyMs: null, goldenAnswersTrusted: false,
            skipped: true, skipReason: 'required key absent',
        ),
    ]);

    expect($report->byCondition())->toBe([]);
});

test('byCondition returns averaged latency per condition', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.5, 0.1, latencyMs: 1000),
        makeResult('none', 0.5, 0.1, latencyMs: 2000),
    ]);

    expect($report->byCondition()['none']['avgLatencyMs'])->toBe(1500);
});

test('byCondition computes sample standard deviation of latency', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.5, 0.1, latencyMs: 1000),
        makeResult('none', 0.5, 0.1, latencyMs: 2000),
    ]);

    // sample stddev of [1000, 2000]: mean 1500, variance (250000+250000)/1 = 500000, sqrt ≈ 707.11
    expect($report->byCondition()['none']['latencyStdDevMs'])->toBe(707);
});

test('byCondition returns null latency stddev when fewer than two results', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.5, 0.1, latencyMs: 1000),
    ]);

    expect($report->byCondition()['none']['latencyStdDevMs'])->toBeNull();
});

test('byCondition averages judge latency only over results carrying judge data', function () {
    $report = new BenchmarkReport([
        makeResult('necromancer', 0.9, 0.0, judgeScore: 8.0, judgeLatencyMs: 400),
        makeResult('necromancer', 0.9, 0.0, judgeScore: 8.0, judgeLatencyMs: 600),
        makeResult('necromancer', 0.9, 0.0, judgeScore: null, judgeLatencyMs: null),
    ]);

    expect($report->byCondition()['necromancer']['avgJudgeLatencyMs'])->toBe(500);
});

test('byCondition returns null judge latency aggregates when no result carries judge data', function () {
    $report = new BenchmarkReport([
        makeResult('none', 0.5, 0.1),
    ]);

    $by = $report->byCondition()['none'];

    expect($by['avgJudgeLatencyMs'])->toBeNull()
        ->and($by['judgeLatencyStdDevMs'])->toBeNull();
});

test('byCondition returns null judge latency stddev when fewer than two judged results', function () {
    $report = new BenchmarkReport([
        makeResult('necromancer', 0.9, 0.0, judgeScore: 8.0, judgeLatencyMs: 400),
    ]);

    expect($report->byCondition()['necromancer']['judgeLatencyStdDevMs'])->toBeNull();
});
