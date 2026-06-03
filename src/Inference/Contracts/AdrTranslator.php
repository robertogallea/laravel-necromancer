<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference\Contracts;

use LaravelNecromancer\Inference\AdrInferenceResult;
use LaravelNecromancer\Inference\InferredAdr;

interface AdrTranslator
{
    /**
     * @param  list<InferredAdr>  $adrs
     */
    public function translate(array $adrs, string $targetLocale, ?string $provider = null, ?string $model = null, ?float $temperature = null): AdrInferenceResult;
}
