<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Metadata\AnnotatedArtifact;

final class InconsistentFlowMetadataCheck implements CheckInterface
{
    private const CHECKED_FIELDS = ['domain', 'risk'];

    public function run(array $artifacts): CheckResult
    {
        $byFlow = [];

        foreach (AnnotatedArtifact::collect($artifacts) as $artifact) {
            $flow = $artifact->annotations['flow'] ?? null;

            if (! empty($flow)) {
                $byFlow[$flow][] = $artifact;
            }
        }

        $applicable = [];
        $findings = [];

        foreach ($byFlow as $flow => $group) {
            if (count($group) < 2) {
                continue;
            }

            $applicable = [...$applicable, ...$group];
            $findings = [...$findings, ...$this->flowFindings((string) $flow, $group)];
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }

    /**
     * Builds at most one Finding per artifact, even when it disagrees with
     * its flow siblings on more than one checked field.
     *
     * @param  list<AnnotatedArtifact>  $group
     * @return list<Finding>
     */
    private function flowFindings(string $flow, array $group): array
    {
        $summariesByIndex = [];

        foreach (self::CHECKED_FIELDS as $field) {
            $distinct = $this->distinctValues($group, $field);

            if (count($distinct) < 2) {
                continue;
            }

            sort($distinct);
            $summary = "{$field}: ".implode(', ', $distinct);

            foreach ($group as $index => $artifact) {
                if (! empty($artifact->annotations[$field] ?? null)) {
                    $summariesByIndex[$index][] = $summary;
                }
            }
        }

        $findings = [];

        foreach ($summariesByIndex as $index => $summaries) {
            $artifact = $group[$index];

            $findings[] = new Finding(
                severity: 'warning',
                message: "Artifacts in flow '{$flow}' declare inconsistent ".implode('; ', $summaries),
                artifactType: $artifact->type,
                context: $artifact->label,
                source: $artifact->source,
            );
        }

        return $findings;
    }

    /**
     * @param  list<AnnotatedArtifact>  $group
     * @return list<string>
     */
    private function distinctValues(array $group, string $field): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (AnnotatedArtifact $a): string => (string) ($a->annotations[$field] ?? ''),
            $group,
        ), fn (string $v): bool => $v !== '')));
    }
}
