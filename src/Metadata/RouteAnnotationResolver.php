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
     */
    public function resolve(array $raw): RouteAnnotationResolution
    {
        $compatibility = (new RouteMetadataNormalizer($this->namespace))->normalize($raw);
        $diagnostics = $this->legacyDiagnostics($raw);
        $adrs = $this->canonicalList([
            $compatibility['adr'] ?? null,
            ...($compatibility['adrs'] ?? []),
        ]);

        if ($adrs !== []) {
            $compatibility['adr'] = $adrs[0];
            $compatibility['adrs'] = $adrs;
        }

        $domain = $this->nonEmptyString($compatibility['domain'] ?? null);
        $flow = $this->nonEmptyString($compatibility['flow'] ?? null);
        $capability = $this->nonEmptyString($compatibility['capability'] ?? null);
        $summary = $this->nonEmptyString($compatibility['summary'] ?? null);

        $risk = $this->nonEmptyString($compatibility['risk'] ?? null);
        $externalServices = $this->canonicalList($compatibility['external_services'] ?? []);

        return new RouteAnnotationResolution(
            compatibility: $compatibility,
            annotations: new ArtifactAnnotations(
                domain: $domain,
                flow: $flow,
                capability: $capability,
                summary: $summary,
                risk: $risk === null ? null : Risk::tryFrom($risk),
                externalServices: $externalServices,
                adrs: $adrs,
            ),
            diagnostics: $diagnostics,
        );
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

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function legacyDiagnostics(array $raw): array
    {
        $declared = $raw[$this->namespace] ?? null;

        if (! is_array($declared)) {
            return [];
        }

        $diagnostics = [];
        foreach (['domain', 'flow', 'capability', 'summary'] as $field) {
            if (array_key_exists($field, $declared) && (! $this->isLegacyScalar($declared[$field]) || trim((string) $declared[$field]) === '')) {
                $diagnostics[] = "AN_LEGACY_VALUE: route metadata {$field} cannot enter Annotation Schema v1.";
            }
        }

        if (array_key_exists('risk', $declared)) {
            $risk = $declared['risk'];
            if (! $this->isLegacyScalar($risk) || Risk::tryFrom(trim((string) $risk)) === null) {
                $diagnostics[] = 'AN_LEGACY_RISK: route metadata risk is not a Schema v1 Risk value.';
            }
        }

        foreach (['external_services', 'adrs'] as $field) {
            if (! array_key_exists($field, $declared)) {
                continue;
            }

            if ($field === 'adrs' && ! is_array($declared[$field])) {
                $diagnostics[] = 'AN_LEGACY_VALUE: route metadata adrs must be a list for Annotation Schema v1.';
            }

            $values = is_array($declared[$field]) ? $declared[$field] : [$declared[$field]];
            foreach ($values as $value) {
                if (! $this->isLegacyScalar($value) || trim((string) $value) === '') {
                    $diagnostics[] = "AN_LEGACY_VALUE: route metadata {$field} contains a value outside Annotation Schema v1.";
                    break;
                }
            }
        }

        return $diagnostics;
    }

    private function isLegacyScalar(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }
}
