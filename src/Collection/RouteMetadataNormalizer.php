<?php

declare(strict_types=1);

namespace LaravelNecromancer\Collection;

final readonly class RouteMetadataNormalizer
{
    /**
     * @var list<string>
     */
    public const HIGH_RISK_LEVELS = ['high', 'critical'];

    public function __construct(private string $namespace = 'necromancer') {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{domain?: string, flow?: string, capability?: string, summary?: string, risk?: string, external_services?: list<string>, adr?: string, adrs?: list<string>}
     */
    public function normalize(array $raw): array
    {
        $namespaced = $raw[$this->namespace] ?? null;

        if (! is_array($namespaced)) {
            return [];
        }

        return array_filter([
            'domain' => $this->stringOrNull($namespaced['domain'] ?? null),
            'flow' => $this->stringOrNull($namespaced['flow'] ?? null),
            'capability' => $this->stringOrNull($namespaced['capability'] ?? null),
            'summary' => $this->stringOrNull($namespaced['summary'] ?? null),
            'risk' => $this->stringOrNull($namespaced['risk'] ?? null),
            'external_services' => $this->stringList($namespaced['external_services'] ?? null),
            'adr' => $this->stringOrNull($namespaced['adr'] ?? null),
            'adrs' => $this->stringList($namespaced['adrs'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringOrNull($item),
            $values,
        )));
    }
}
