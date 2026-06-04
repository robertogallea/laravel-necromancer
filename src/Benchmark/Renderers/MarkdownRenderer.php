<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Renderers;

use LaravelNecromancer\Benchmark\BenchmarkReport;

final class MarkdownRenderer
{
    public function render(BenchmarkReport $report): string
    {
        $by = $report->byCondition();

        $lines = [
            '# Necromancer Benchmark Results',
            '',
            '| Condition | Accuracy | Hallucination Rate | Quality Score | Avg Tokens |',
            '|---|---|---|---|---|',
        ];

        foreach ($by as $condition => $stats) {
            $lines[] = sprintf(
                '| %s | %.0f%% | %.0f%% | %.1f / 10 | %d |',
                $this->labelFor($condition),
                $stats['accuracy'] * 100,
                $stats['hallucinationRate'] * 100,
                $stats['qualityScore'],
                $stats['avgPromptTokens'] + $stats['avgCompletionTokens'],
            );
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

        return implode("\n", $lines)."\n";
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
