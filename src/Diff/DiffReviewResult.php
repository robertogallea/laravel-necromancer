<?php

declare(strict_types=1);

namespace LaravelNecromancer\Diff;

final readonly class DiffReviewResult
{
    /**
     * @param  list<string>  $evidence
     * @param  list<string>  $risks
     * @param  list<string>  $reviewerQuestions
     */
    public function __construct(
        public string $summary,
        public array $evidence,
        public array $risks,
        public array $reviewerQuestions,
        public int $promptTokens,
        public int $completionTokens,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
