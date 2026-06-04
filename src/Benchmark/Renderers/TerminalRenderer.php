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

        $lines = [
            '',
            '  ─── Results ─────────────────────────────────────────────────────────',
            sprintf('  %-20s %8s  %8s  %8s  %8s', 'Condition', 'Accuracy', 'Halluc.', 'Quality', 'Tokens'),
        ];

        foreach ($by as $condition => $stats) {
            $lines[] = sprintf(
                '  %-20s %7.0f%%  %7.0f%%  %8.1f  %8d',
                $this->labelFor($condition),
                $stats['accuracy'] * 100,
                $stats['hallucinationRate'] * 100,
                $stats['qualityScore'],
                $stats['avgPromptTokens'] + $stats['avgCompletionTokens'],
            );
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

        $lines[] = '';

        return implode("\n", $lines);
    }

    private function labelFor(string $condition): string
    {
        return match ($condition) {
            'none' => 'No context',
            'manual' => 'Manual CLAUDE.md',
            'necromancer' => 'Necromancer',
            default => $condition,
        };
    }
}
