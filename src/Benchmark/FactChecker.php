<?php

declare(strict_types=1);

namespace LaravelNecromancer\Benchmark;

final class FactChecker
{
    /**
     * @param  array{must_contain?: string[], must_not_contain?: string[], must_recall_from?: string}  $assertions
     * @param  array<string, array{value: mixed, trusted: bool}>  $resolvedFacts
     * @return array{accuracy: float, hallucinationRate: float}
     */
    public function check(string $response, array $assertions, array $resolvedFacts = []): array
    {
        $accuracy = $this->computeAccuracy($response, $assertions, $resolvedFacts);
        $hallucinationRate = $this->computeHallucinationRate($response, $assertions);

        return compact('accuracy', 'hallucinationRate');
    }

    /** @param array<string, array{value: mixed, trusted: bool}> $resolvedFacts */
    private function computeAccuracy(string $response, array $assertions, array $resolvedFacts): float
    {
        $recallKey = $assertions['must_recall_from'] ?? null;
        $mustContain = $assertions['must_contain'] ?? [];

        $recallRate = null;
        $keywordRate = null;

        if ($recallKey !== null && isset($resolvedFacts[$recallKey])) {
            $items = $this->flattenForRecall($resolvedFacts[$recallKey]['value']);

            if (! empty($items)) {
                $found = count(array_filter($items, fn (string $item): bool => mb_stripos($response, $item) !== false));
                $recallRate = (float) $found / count($items);
            }
        }

        if (! empty($mustContain)) {
            $keywordRate = (float) count(array_filter($mustContain, fn (string $s): bool => mb_stripos($response, $s) !== false)) / count($mustContain);
        }

        if ($recallRate !== null && $keywordRate !== null) {
            return ($recallRate + $keywordRate) / 2.0;
        }

        return $recallRate ?? $keywordRate ?? 1.0;
    }

    private function computeHallucinationRate(string $response, array $assertions): float
    {
        $mustNotContain = $assertions['must_not_contain'] ?? [];

        if (empty($mustNotContain)) {
            return 0.0;
        }

        return (float) count(array_filter($mustNotContain, fn (string $s): bool => mb_stripos($response, $s) !== false)) / count($mustNotContain);
    }

    /** @return string[] */
    private function flattenForRecall(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_map('strval', array_is_list($value) ? $value : array_keys($value)));
        }

        return [(string) $value];
    }
}
