<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Renderers;

use LaravelNecromancer\Benchmark\BenchmarkReport;

final class MarkdownRenderer
{
    public function render(BenchmarkReport $report): string
    {
        $by = $report->byCondition();

        $hasJudgeLatency = $this->hasJudgeLatency($by);

        $header = '| Condition | Accuracy | Hallucination Rate | Quality Score | Avg Tokens | Latency |';
        $separator = '|---|---|---|---|---|---|';

        if ($hasJudgeLatency) {
            $header .= ' Judge Latency |';
            $separator .= '---|';
        }

        $lines = [
            '# Necromancer Benchmark Results',
            '',
            $header,
            $separator,
        ];

        foreach ($by as $condition => $stats) {
            $row = sprintf(
                '| %s | %.0f%% | %.0f%% | %.1f / 10 | %d | %s |',
                $this->labelFor($condition),
                $stats['accuracy'] * 100,
                $stats['hallucinationRate'] * 100,
                $stats['qualityScore'],
                $stats['avgPromptTokens'] + $stats['avgCompletionTokens'],
                $this->formatLatency($stats['avgLatencyMs'], $stats['latencyStdDevMs']),
            );

            if ($hasJudgeLatency) {
                $judgeLatency = $stats['avgJudgeLatencyMs'] !== null
                    ? $this->formatLatency($stats['avgJudgeLatencyMs'], $stats['judgeLatencyStdDevMs'])
                    : '—';
                $row .= " {$judgeLatency} |";
            }

            $lines[] = $row;
        }

        if (isset($by['necromancer'], $by['manual'])) {
            $accDiff = ($by['necromancer']['accuracy'] - $by['manual']['accuracy']) * 100;
            $hallDiff = ($by['manual']['hallucinationRate'] - $by['necromancer']['hallucinationRate']) * 100;
            $lines[] = '';
            $lines[] = sprintf(
                '**Necromancer vs manual:** %+.0fpp accuracy · %+.0fpp hallucination reduction',
                $accDiff,
                $hallDiff,
            );
        }

        if (isset($by['necromancer-mcp'], $by['necromancer'])) {
            $mcpAccDiff = ($by['necromancer-mcp']['accuracy'] - $by['necromancer']['accuracy']) * 100;
            $mcpHallDiff = ($by['necromancer']['hallucinationRate'] - $by['necromancer-mcp']['hallucinationRate']) * 100;
            $lines[] = '';
            $lines[] = sprintf(
                '**Necromancer (MCP) vs Necromancer (static):** %+.0fpp accuracy · %+.0fpp hallucination reduction',
                $mcpAccDiff,
                $mcpHallDiff,
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function labelFor(string $condition): string
    {
        return match ($condition) {
            'none' => 'No context',
            'manual' => 'Manual CLAUDE.md',
            'necromancer' => 'Necromancer',
            'necromancer-mcp' => 'Necromancer (MCP)',
            default => $condition,
        };
    }

    /** @param array<string, array{avgJudgeLatencyMs: ?int}> $by */
    private function hasJudgeLatency(array $by): bool
    {
        foreach ($by as $stats) {
            if ($stats['avgJudgeLatencyMs'] !== null) {
                return true;
            }
        }

        return false;
    }

    private function formatLatency(int $avgMs, ?int $stdDevMs): string
    {
        $avg = number_format($avgMs / 1000, 1);

        if ($stdDevMs === null) {
            return "{$avg}s";
        }

        $stdDev = number_format($stdDevMs / 1000, 1);

        return "{$avg}s ± {$stdDev}s";
    }
}
