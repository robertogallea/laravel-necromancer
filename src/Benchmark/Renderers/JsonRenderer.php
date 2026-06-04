<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark\Renderers;

use JsonException;
use LaravelNecromancer\Benchmark\BenchmarkReport;

final class JsonRenderer
{
    /** @throws JsonException */
    public function render(BenchmarkReport $report): string
    {
        $payload = [
            'summary' => $report->byCondition(),
            'results' => array_map(fn ($r) => [
                'task_id' => $r->taskId,
                'task_type' => $r->taskType,
                'condition' => $r->condition,
                'accuracy' => $r->accuracy,
                'hallucination_rate' => $r->hallucinationRate,
                'judge_score' => $r->judgeScore,
                'prompt_tokens' => $r->promptTokens,
                'completion_tokens' => $r->completionTokens,
                'judge_tokens' => $r->judgeTokens,
                'golden_answers_trusted' => $r->goldenAnswersTrusted,
            ], $report->results),
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
