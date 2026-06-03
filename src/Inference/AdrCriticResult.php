<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

final readonly class AdrCriticResult
{
    /**
     * @param  list<InferredAdr>  $adrs
     * @param  bool  $satisfied  True when the critic believes no further round is needed
     */
    public function __construct(
        public array $adrs,
        public bool $satisfied,
        public int $promptTokens,
        public int $completionTokens,
    ) {}
}
