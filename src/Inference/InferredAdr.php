<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

final readonly class InferredAdr
{
    public function __construct(
        public string $title,
        public string $slug,
        public string $status,
        public string $context,
        public string $decision,
        public string $consequences,
        public string $counter_evidence = '',
        public string $dimension = 'general',
        public string $confidence = 'medium',
    ) {}
}
