<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use LaravelNecromancer\Collection\RouteMetadataNormalizer;

/**
 * Resolves native Laravel route metadata into the canonical Annotation
 * Schema v1 projection. RouteMetadataNormalizer stays an internal
 * extraction step only — its output is never itself exposed in the
 * manifest.
 */
final readonly class RouteAnnotationResolver
{
    public function __construct(private string $namespace = 'necromancer') {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public function resolve(array $raw): RouteAnnotationResolution
    {
        $normalized = (new RouteMetadataNormalizer($this->namespace))->normalize($raw);
        $diagnostics = $this->schemaIncompatibleDiagnostics($raw);
        $adrs = $this->canonicalList([
            $normalized['adr'] ?? null,
            ...($normalized['adrs'] ?? []),
        ]);

        $domain = $this->nonEmptyString($normalized['domain'] ?? null);
        $flow = $this->nonEmptyString($normalized['flow'] ?? null);
        $capability = $this->nonEmptyString($normalized['capability'] ?? null);
        $summary = $this->nonEmptyString($normalized['summary'] ?? null);

        $risk = $this->nonEmptyString($normalized['risk'] ?? null);
        $externalServices = $this->canonicalList($normalized['external_services'] ?? []);

        return new RouteAnnotationResolution(
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
     * A native Route::metadata() declaration can carry a value that simply
     * cannot enter the closed Annotation Schema v1 (e.g. a non-scalar
     * domain, or a risk string outside the Risk enum) — independent of
     * manifest schema versioning, this can happen on any scan of any
     * current manifest, so it stays a permanent validation signal rather
     * than something tied to legacy manifest support.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function schemaIncompatibleDiagnostics(array $raw): array
    {
        $declared = $raw[$this->namespace] ?? null;

        if (! is_array($declared)) {
            return [];
        }

        $diagnostics = [];
        foreach (['domain', 'flow', 'capability', 'summary'] as $field) {
            if (array_key_exists($field, $declared) && (! $this->isLegacyScalar($declared[$field]) || trim((string) $declared[$field]) === '')) {
                $diagnostics[] = "AN_SCHEMA_INCOMPATIBLE_VALUE: route metadata {$field} cannot enter Annotation Schema v1.";
            }
        }

        if (array_key_exists('risk', $declared)) {
            $risk = $declared['risk'];
            if (! $this->isLegacyScalar($risk) || Risk::tryFrom(trim((string) $risk)) === null) {
                $diagnostics[] = 'AN_SCHEMA_INCOMPATIBLE_RISK: route metadata risk is not a Schema v1 Risk value.';
            }
        }

        foreach (['external_services', 'adrs'] as $field) {
            if (! array_key_exists($field, $declared)) {
                continue;
            }

            if ($field === 'adrs' && ! is_array($declared[$field])) {
                $diagnostics[] = 'AN_SCHEMA_INCOMPATIBLE_VALUE: route metadata adrs must be a list for Annotation Schema v1.';
            }

            $values = is_array($declared[$field]) ? $declared[$field] : [$declared[$field]];
            foreach ($values as $value) {
                if (! $this->isLegacyScalar($value) || trim((string) $value) === '') {
                    $diagnostics[] = "AN_SCHEMA_INCOMPATIBLE_VALUE: route metadata {$field} contains a value outside Annotation Schema v1.";
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
