<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

final readonly class BenchmarkResult
{
    public function __construct(
        public string $taskId,
        public string $taskType,
        public string $condition,
        public string $prompt,
        public string $response,
        public int $promptTokens,
        public int $completionTokens,
        public int $latencyMs,
        public float $accuracy,
        public float $hallucinationRate,
        public ?float $judgeScore,
        public ?int $judgeTokens,
        public ?int $judgeLatencyMs,
        public bool $goldenAnswersTrusted,
        public bool $skipped = false,
        public ?string $skipReason = null,
    ) {}
}
