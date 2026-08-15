<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

final class BenchmarkReport
{
    /** @param BenchmarkResult[] $results */
    public function __construct(public readonly array $results) {}

    /**
     * @return array<string, array{accuracy: float, hallucinationRate: float, qualityScore: float, avgPromptTokens: int, avgCompletionTokens: int, totalJudgeTokens: int, avgLatencyMs: int, latencyStdDevMs: ?int, avgJudgeLatencyMs: ?int, judgeLatencyStdDevMs: ?int}>
     */
    public function byCondition(): array
    {
        $grouped = [];

        foreach ($this->results as $result) {
            if (! $result->skipped) {
                $grouped[$result->condition][] = $result;
            }
        }

        $summary = [];

        foreach ($grouped as $condition => $conditionResults) {
            $count = count($conditionResults);
            $judged = array_filter($conditionResults, fn (BenchmarkResult $r): bool => $r->judgeScore !== null);

            $latencies = array_map(fn (BenchmarkResult $r): int => $r->latencyMs, $conditionResults);
            $judgeLatencies = array_values(array_filter(
                array_map(fn (BenchmarkResult $r): ?int => $r->judgeLatencyMs, $conditionResults),
                fn (?int $v): bool => $v !== null,
            ));

            $summary[$condition] = [
                'accuracy' => array_sum(array_map(fn (BenchmarkResult $r): float => $r->accuracy, $conditionResults)) / $count,
                'hallucinationRate' => array_sum(array_map(fn (BenchmarkResult $r): float => $r->hallucinationRate, $conditionResults)) / $count,
                'qualityScore' => count($judged) > 0
                    ? array_sum(array_map(fn (BenchmarkResult $r): float => $r->judgeScore ?? 0.0, $judged)) / count($judged)
                    : 0.0,
                'avgPromptTokens' => (int) round(array_sum(array_map(fn (BenchmarkResult $r): int => $r->promptTokens, $conditionResults)) / $count),
                'avgCompletionTokens' => (int) round(array_sum(array_map(fn (BenchmarkResult $r): int => $r->completionTokens, $conditionResults)) / $count),
                'totalJudgeTokens' => (int) array_sum(array_map(fn (BenchmarkResult $r): int => $r->judgeTokens ?? 0, $conditionResults)),
                'avgLatencyMs' => (int) round(array_sum($latencies) / $count),
                'latencyStdDevMs' => $this->sampleStdDevMs($latencies),
                'avgJudgeLatencyMs' => $judgeLatencies !== [] ? (int) round(array_sum($judgeLatencies) / count($judgeLatencies)) : null,
                'judgeLatencyStdDevMs' => $this->sampleStdDevMs($judgeLatencies),
            ];
        }

        return $summary;
    }

    /** @param int[] $values */
    private function sampleStdDevMs(array $values): ?int
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn (int $v): float => ($v - $mean) ** 2, $values)) / ($n - 1);

        return (int) round(sqrt($variance));
    }
}
