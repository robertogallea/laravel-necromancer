<?php

declare(strict_types=1);

namespace LaravelNecromancer\Doctor;

final readonly class DimensionResult
{
    public function __construct(
        public string $key,
        public string $label,
        public float $score,
        public string $detail,
        public float $weight,
    ) {}

    public function percentage(): int
    {
        return (int) round($this->score * 100);
    }
}
