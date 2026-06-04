<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

final class FactChecker
{
    /**
     * @param  array{must_contain: string[], must_not_contain: string[]}  $assertions
     * @return array{accuracy: float, hallucinationRate: float}
     */
    public function check(string $response, array $assertions): array
    {
        $mustContain = $assertions['must_contain'] ?? [];
        $mustNotContain = $assertions['must_not_contain'] ?? [];

        $accuracy = empty($mustContain)
            ? 1.0
            : (float) count(array_filter($mustContain, fn (string $s): bool => str_contains($response, $s))) / count($mustContain);

        $hallucinationRate = empty($mustNotContain)
            ? 0.0
            : (float) count(array_filter($mustNotContain, fn (string $s): bool => str_contains($response, $s))) / count($mustNotContain);

        return compact('accuracy', 'hallucinationRate');
    }
}
