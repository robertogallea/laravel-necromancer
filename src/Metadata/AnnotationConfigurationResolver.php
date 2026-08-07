<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

use InvalidArgumentException;
use LaravelNecromancer\Manifest\ArtifactId;

/**
 * Resolves the `necromancer.annotations` exact-ID configuration mapping and
 * applies it, fill-only, to an already ID-assigned artifact collection. This is
 * the sole annotation source for non-reflectable artifact families (closures,
 * test files, gates, scheduled tasks) and a registration-specific escape hatch
 * for every other family, per Artifact Annotations v1 §7-§9.
 *
 * Not `readonly`: unlike its sibling resolvers, validating a mapping depends on
 * the scan's scope (AN-CONFIG-004), so nothing here is safely memoizable across
 * calls with different scopes.
 */
final class AnnotationConfigurationResolver
{
    private const KNOWN_FIELDS = [
        'domain', 'flow', 'capability', 'summary', 'risk', 'external_services', 'adrs',
    ];

    private const MIDDLEWARE_SCOPES = ['global', 'group', 'alias'];

    private const GATE_KINDS = ['ability', 'before_hook', 'after_hook'];

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function __construct(private readonly array $configuration) {}

    /**
     * @return array<string, ArtifactAnnotations>
     */
    public function mappings(): array
    {
        return $this->mappingsInScope(ArtifactId::supportedTypes());
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $artifacts
     * @param  list<string>  $scopeTypes
     * @return array{0: array<string, list<array<string, mixed>>>, 1: list<string>}
     */
    public function apply(array $artifacts, array $scopeTypes): array
    {
        $mappings = $this->mappingsInScope($scopeTypes);

        if ($mappings === []) {
            return [$artifacts, []];
        }

        $index = [];

        foreach ($artifacts as $type => $items) {
            foreach ($items as $position => $item) {
                $id = $item['id'] ?? null;

                if (is_string($id)) {
                    $index[$id] = [$type, $position];
                }
            }
        }

        $diagnostics = [];

        foreach ($mappings as $id => $configAnnotations) {
            if (! isset($index[$id])) {
                $diagnostics[] = "AN_CONFIG_UNMATCHED: exact-ID mapping '{$id}' has no matching artifact in this scan.";

                continue;
            }

            [$type, $position] = $index[$id];
            $data = $artifacts[$type][$position];
            $existing = isset($data['annotations']) && is_array($data['annotations'])
                ? ArtifactAnnotations::fromArray($data['annotations'])
                : new ArtifactAnnotations;

            [$merged, $mergeDiagnostics] = $this->fillOnly($existing, $configAnnotations, $id);
            $diagnostics = [...$diagnostics, ...$mergeDiagnostics];

            if (! $merged->isEmpty()) {
                $data['annotations'] = $merged->jsonSerialize();
                $artifacts[$type][$position] = $data;
            }
        }

        return [$artifacts, $diagnostics];
    }

    /**
     * A mapping whose type falls outside the current scan's scope is not
     * evaluated at all — not even for schema errors — so an unrelated partial
     * scan (e.g. `--only=routes`) never fails on an out-of-scope mistake
     * (AN-CONFIG-004). A mapping with no recognizable type prefix has no scope
     * to be "outside of", so it is always validated.
     *
     * @param  list<string>  $scopeTypes
     * @return array<string, ArtifactAnnotations>
     */
    private function mappingsInScope(array $scopeTypes): array
    {
        $mappings = [];

        foreach ($this->configuration as $id => $fields) {
            $id = (string) $id;
            $type = $this->typeOf($id);

            if ($type !== null && ! in_array($type, $scopeTypes, true)) {
                continue;
            }

            $this->validateId($id, $type);
            $mappings[$id] = $this->resolveAnnotations($id, is_array($fields) ? $fields : []);
        }

        return $mappings;
    }

    /**
     * @return non-empty-string|null
     */
    private function typeOf(string $id): ?string
    {
        $type = strstr($id, ':', true);

        return is_string($type) && in_array($type, ArtifactId::supportedTypes(), true) ? $type : null;
    }

    private function validateId(string $id, ?string $type): void
    {
        if (str_contains($id, '*')) {
            throw new InvalidArgumentException(
                "AN_SCHEMA_INVALID_VALUE: exact-ID mapping key '{$id}' is not a valid canonical Artifact ID; wildcards are not supported.",
            );
        }

        if ($type === null) {
            throw new InvalidArgumentException(
                "AN_SCHEMA_INVALID_VALUE: exact-ID mapping key '{$id}' does not start with a known artifact-type prefix.",
            );
        }

        $segments = explode(':', $id);

        if (! $this->hasCanonicalShape($type, $segments)) {
            throw new InvalidArgumentException(
                "AN_SCHEMA_INVALID_VALUE: exact-ID mapping key '{$id}' does not match the canonical Artifact ID shape for '{$type}'.",
            );
        }
    }

    /**
     * Mirrors the identity shapes `ArtifactId` itself derives (spec §6.1), so a
     * mapping key that could never be a real Artifact ID fails validation
     * instead of silently producing an unmatched warning.
     *
     * @param  list<string>  $segments
     */
    private function hasCanonicalShape(string $type, array $segments): bool
    {
        return match ($type) {
            'routes' => count($segments) === 3
                && preg_match('/^[A-Z]+(\|[A-Z]+)*$/', $segments[1]) === 1
                && $segments[2] !== '',
            'tests' => count($segments) === 2 && $segments[1] !== '',
            'middleware' => in_array($segments[1] ?? null, self::MIDDLEWARE_SCOPES, true) && match ($segments[1]) {
                'global', 'alias' => count($segments) === 3 && $segments[2] !== '',
                default => count($segments) === 4 && $segments[2] !== '' && $segments[3] !== '',
            },
            'gates' => in_array($segments[1] ?? null, self::GATE_KINDS, true) && match ($segments[1]) {
                'ability' => count($segments) === 3 && $segments[2] !== '',
                default => count($segments) === 3 && preg_match('/^\d+$/', $segments[2]) === 1,
            },
            'scheduled_tasks' => count($segments) === 3
                && preg_match('/^[0-9a-f]{64}$/', $segments[1]) === 1
                && preg_match('/^[1-9]\d*$/', $segments[2]) === 1,
            default => count($segments) === 2 && $segments[1] !== '',
        };
    }

    /**
     * @param  array<array-key, mixed>  $fields
     */
    private function resolveAnnotations(string $id, array $fields): ArtifactAnnotations
    {
        foreach (array_keys($fields) as $field) {
            if (! in_array($field, self::KNOWN_FIELDS, true)) {
                throw new InvalidArgumentException(
                    "AN_SCHEMA_UNKNOWN_FIELD: exact-ID mapping '{$id}' declares unknown field '{$field}'.",
                );
            }
        }

        return new ArtifactAnnotations(
            domain: $this->scalar($id, 'domain', $fields['domain'] ?? null),
            flow: $this->scalar($id, 'flow', $fields['flow'] ?? null),
            capability: $this->scalar($id, 'capability', $fields['capability'] ?? null),
            summary: $this->scalar($id, 'summary', $fields['summary'] ?? null),
            risk: $this->risk($id, $fields['risk'] ?? null),
            externalServices: $this->list($id, 'external_services', $fields['external_services'] ?? []),
            adrs: $this->list($id, 'adrs', $fields['adrs'] ?? []),
        );
    }

    private function scalar(string $id, string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "AN_SCHEMA_INVALID_VALUE: exact-ID mapping '{$id}' field '{$field}' must be a non-empty string.",
            );
        }

        return trim($value);
    }

    private function risk(string $id, mixed $value): ?Risk
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || Risk::tryFrom($value) === null) {
            throw new InvalidArgumentException(
                "AN_SCHEMA_INVALID_VALUE: exact-ID mapping '{$id}' field 'risk' must be one of: ".
                implode(', ', array_map(static fn (Risk $case): string => $case->value, Risk::cases())).'.',
            );
        }

        return Risk::from($value);
    }

    /**
     * @return list<string>
     */
    private function list(string $id, string $field, mixed $values): array
    {
        if ($values === null) {
            return [];
        }

        if (! is_array($values)) {
            throw new InvalidArgumentException(
                "AN_SCHEMA_INVALID_VALUE: exact-ID mapping '{$id}' field '{$field}' must be a list of strings.",
            );
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(
                    "AN_SCHEMA_INVALID_VALUE: exact-ID mapping '{$id}' field '{$field}' items must be non-empty strings.",
                );
            }

            $trimmed = trim($value);

            if (! in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    /**
     * The exact-ID mapping is a fill-only source (AN-MERGE-003): an already
     * resolved scalar always wins over a conflicting config value, which is
     * exactly `AnnotationMerger`'s existing precedence rule with the config
     * value passed as the base and the existing value as the more specific
     * declaration. List fields don't fit that reuse — AN-MERGE-005 requires
     * configuration values to be appended *after* the existing ones, while
     * `AnnotationMerger` merges base-then-specific — so they're combined here
     * instead.
     *
     * @return array{0: ArtifactAnnotations, 1: list<string>}
     */
    private function fillOnly(ArtifactAnnotations $existing, ArtifactAnnotations $config, string $id): array
    {
        [$scalars, $diagnostics] = (new AnnotationMerger)->merge(
            base: $config,
            moreSpecific: $existing,
            warnOnConflict: true,
            artifactLabel: $id,
            baseSourceLabel: 'the exact-ID mapping',
            moreSpecificSourceLabel: 'the existing declaration',
        );

        $annotations = new ArtifactAnnotations(
            domain: $scalars->domain,
            flow: $scalars->flow,
            capability: $scalars->capability,
            summary: $scalars->summary,
            risk: $scalars->risk,
            externalServices: $this->appendList($existing->externalServices, $config->externalServices),
            adrs: $this->appendList($existing->adrs, $config->adrs),
        );

        return [$annotations, $diagnostics];
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $config
     * @return list<string>
     */
    private function appendList(array $existing, array $config): array
    {
        $result = $existing;

        foreach ($config as $value) {
            if (! in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
