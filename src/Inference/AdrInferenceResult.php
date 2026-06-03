<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

final readonly class AdrInferenceResult
{
    /**
     * @param  list<InferredAdr>  $adrs
     */
    public function __construct(
        public array $adrs,
        public int $promptTokens,
        public int $completionTokens,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
