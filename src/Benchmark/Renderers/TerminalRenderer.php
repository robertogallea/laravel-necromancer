<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Renderers;

use LaravelNecromancer\Benchmark\BenchmarkReport;

final class TerminalRenderer
{
    public function render(BenchmarkReport $report): string
    {
        $by = $report->byCondition();

        if (empty($by)) {
            return '  No results.';
        }

        $hasJudgeLatency = $this->hasJudgeLatency($by);

        $headerFormat = '  %-20s %8s  %8s  %8s  %8s  %13s';
        $rowFormat = '  %-20s %7.0f%%  %7.0f%%  %8.1f  %8d  %13s';
        $headerArgs = ['Condition', 'Accuracy', 'Halluc.', 'Quality', 'Tokens', 'Latency'];

        if ($hasJudgeLatency) {
            $headerFormat .= '  %13s';
            $rowFormat .= '  %13s';
            $headerArgs[] = 'Judge Latency';
        }

        $lines = [
            '',
            '  ─── Results ─────────────────────────────────────────────────────────',
            vsprintf($headerFormat, $headerArgs),
        ];

        foreach ($by as $condition => $stats) {
            $rowArgs = [
                $this->labelFor($condition),
                $stats['accuracy'] * 100,
                $stats['hallucinationRate'] * 100,
                $stats['qualityScore'],
                $stats['avgPromptTokens'] + $stats['avgCompletionTokens'],
                $this->formatLatency($stats['avgLatencyMs'], $stats['latencyStdDevMs']),
            ];

            if ($hasJudgeLatency) {
                $rowArgs[] = $stats['avgJudgeLatencyMs'] !== null
                    ? $this->formatLatency($stats['avgJudgeLatencyMs'], $stats['judgeLatencyStdDevMs'])
                    : '—';
            }

            $lines[] = vsprintf($rowFormat, $rowArgs);
        }

        $lines[] = '  ──────────────────────────────────────────────────────────────────────';

        if (isset($by['necromancer'], $by['manual'])) {
            $accDiff = ($by['necromancer']['accuracy'] - $by['manual']['accuracy']) * 100;
            $hallDiff = ($by['manual']['hallucinationRate'] - $by['necromancer']['hallucinationRate']) * 100;
            $sign = $accDiff >= 0 ? '+' : '';
            $lines[] = '';
            $lines[] = sprintf(
                '  Necromancer vs manual:  %s%.0fpp accuracy · %+.0fpp fewer hallucinations',
                $sign,
                $accDiff,
                $hallDiff,
            );
        }

        if (isset($by['necromancer-mcp'], $by['necromancer'])) {
            $mcpAccDiff = ($by['necromancer-mcp']['accuracy'] - $by['necromancer']['accuracy']) * 100;
            $mcpHallDiff = ($by['necromancer']['hallucinationRate'] - $by['necromancer-mcp']['hallucinationRate']) * 100;
            $mcpSign = $mcpAccDiff >= 0 ? '+' : '';
            $lines[] = '';
            $lines[] = sprintf(
                '  Necromancer (MCP) vs Necromancer (static):  %s%.0fpp accuracy · %+.0fpp fewer hallucinations',
                $mcpSign,
                $mcpAccDiff,
                $mcpHallDiff,
            );
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function labelFor(string $condition): string
    {
        return match ($condition) {
            'none' => 'No context',
            'manual' => 'Manual AGENTS.md',
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
