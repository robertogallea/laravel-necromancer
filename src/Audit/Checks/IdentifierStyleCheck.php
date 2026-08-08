<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Metadata\AnnotatedArtifact;

/**
 * AN-SCHEMA-006/AN-AUDIT-005: identifier style is audit guidance, never
 * resolver-enforced. This check flags non-canonical spelling and
 * case-insensitive near-duplicate identifiers without rewriting values.
 */
final class IdentifierStyleCheck implements CheckInterface
{
    private const SCALAR_FIELDS = ['domain', 'flow', 'capability'];

    public function run(array $artifacts): CheckResult
    {
        $entries = $this->entries(AnnotatedArtifact::collect($artifacts));
        $findings = [];
        $flaggedEntries = [];

        foreach ($entries as $index => $entry) {
            if (! $this->isCanonicalForm($entry['field'], $entry['value'])) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: "Non-canonical {$entry['field']} identifier '{$entry['value']}' on {$entry['artifact']->type}: {$entry['artifact']->label}",
                    artifactType: $entry['artifact']->type,
                    context: $entry['artifact']->label,
                    source: $entry['artifact']->source,
                );

                $flaggedEntries[$index] = true;
            }
        }

        // Near-duplicate grouping runs over every entry, canonical or not, so a
        // canonical value (e.g. "billing") is still cross-checked against a
        // case-differing sibling (e.g. "Billing") that was already flagged
        // above as non-canonical — each entry still gets at most one finding.
        foreach ($this->nearDuplicateGroups($entries) as $group) {
            $variants = array_values(array_unique(array_map(fn (array $e): string => $e['value'], $group)));

            foreach ($group as $index => $entry) {
                if (isset($flaggedEntries[$index])) {
                    continue;
                }

                $others = implode(', ', array_filter($variants, fn (string $v): bool => $v !== $entry['value']));

                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: "{$entry['field']} identifier '{$entry['value']}' looks like a near-duplicate of '{$others}' on {$entry['artifact']->type}: {$entry['artifact']->label}",
                    artifactType: $entry['artifact']->type,
                    context: $entry['artifact']->label,
                    source: $entry['artifact']->source,
                );

                $flaggedEntries[$index] = true;
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($entries), findings: $findings);
    }

    /**
     * @param  list<AnnotatedArtifact>  $artifacts
     * @return list<array{artifact: AnnotatedArtifact, field: string, value: string}>
     */
    private function entries(array $artifacts): array
    {
        $entries = [];

        foreach ($artifacts as $artifact) {
            foreach (self::SCALAR_FIELDS as $field) {
                $value = $artifact->annotations[$field] ?? null;

                if (is_string($value) && $value !== '') {
                    $entries[] = ['artifact' => $artifact, 'field' => $field, 'value' => $value];
                }
            }

            foreach ((array) ($artifact->annotations['external_services'] ?? []) as $service) {
                if (is_string($service) && $service !== '') {
                    $entries[] = ['artifact' => $artifact, 'field' => 'external_services', 'value' => $service];
                }
            }
        }

        return $entries;
    }

    private function isCanonicalForm(string $field, string $value): bool
    {
        return match ($field) {
            'capability' => (bool) preg_match('/^[a-z0-9]+(\.[a-z0-9]+)*$/', $value),
            default => (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value),
        };
    }

    /**
     * @param  list<array{artifact: AnnotatedArtifact, field: string, value: string}>  $entries
     * @return list<list<array{artifact: AnnotatedArtifact, field: string, value: string}>>
     */
    private function nearDuplicateGroups(array $entries): array
    {
        $byKey = [];

        foreach ($entries as $index => $entry) {
            $squashed = strtolower(str_replace(['-', '.'], '', $entry['value']));
            $byKey[$entry['field'].'|'.$squashed][$index] = $entry;
        }

        $groups = [];

        foreach ($byKey as $group) {
            $distinctValues = array_unique(array_map(fn (array $e): string => $e['value'], $group));

            if (count($distinctValues) > 1) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}
