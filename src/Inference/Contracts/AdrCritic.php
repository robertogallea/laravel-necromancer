<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference\Contracts;

use LaravelNecromancer\Inference\AdrCriticResult;
use LaravelNecromancer\Inference\InferredAdr;

interface AdrCritic
{
    /**
     * @param  list<InferredAdr>  $adrs
     */
    public function critique(array $adrs, string $manifestSummary, ?string $provider = null, ?string $model = null, ?float $temperature = null): AdrCriticResult;
}
