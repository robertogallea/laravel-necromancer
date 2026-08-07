<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use LaravelNecromancer\Collection\RouteMetadataNormalizer;

/**
 * Resolves native Laravel route metadata into legacy compatibility metadata and
 * the canonical Annotation Schema v1 projection.
 */
final readonly class RouteAnnotationResolver
{
    public function __construct(private string $namespace = 'necromancer') {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{compatibility: array<string, mixed>, annotations: array<string, mixed>}
     */
    public function resolve(array $raw): array
    {
        $compatibility = (new RouteMetadataNormalizer($this->namespace))->normalize($raw);
        $adrs = $this->canonicalList([
            $compatibility['adr'] ?? null,
            ...($compatibility['adrs'] ?? []),
        ]);

        if ($adrs !== []) {
            $compatibility['adr'] = $adrs[0];
            $compatibility['adrs'] = $adrs;
        }

        $annotations = [];

        foreach (['domain', 'flow', 'capability', 'summary'] as $field) {
            $value = $this->nonEmptyString($compatibility[$field] ?? null);

            if ($value !== null) {
                $annotations[$field] = $value;
            }
        }

        $risk = $this->nonEmptyString($compatibility['risk'] ?? null);
        if ($risk !== null && Risk::tryFrom($risk) !== null) {
            $annotations['risk'] = $risk;
        }

        $externalServices = $this->canonicalList($compatibility['external_services'] ?? []);
        if ($externalServices !== []) {
            $annotations['external_services'] = $externalServices;
        }

        if ($adrs !== []) {
            $annotations['adrs'] = $adrs;
        }

        return ['compatibility' => $compatibility, 'annotations' => $annotations];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function canonicalList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $value = $this->nonEmptyString($value);

            if ($value !== null && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }
}
