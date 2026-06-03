<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference\Contracts;

use LaravelNecromancer\Inference\AdrInferenceResult;

interface AdrInferrer
{
    public function infer(string $prompt, ?string $provider = null, ?string $model = null, ?string $locale = null, ?float $temperature = null): AdrInferenceResult;
}
