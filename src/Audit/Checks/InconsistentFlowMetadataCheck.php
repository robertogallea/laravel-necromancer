<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class InconsistentFlowMetadataCheck implements CheckInterface
{
    private const CHECKED_FIELDS = ['domain', 'risk'];

    public function run(array $artifacts): CheckResult
    {
        $byFlow = [];

        foreach ($artifacts['routes'] ?? [] as $route) {
            $flow = $route['route_metadata']['necromancer']['flow'] ?? null;

            if (! empty($flow)) {
                $byFlow[$flow][] = $route;
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
     * Builds at most one Finding per route, even when a route disagrees with
     * its flow siblings on more than one checked field.
     *
     * @param  list<array<string, mixed>>  $group
     * @return list<Finding>
     */
    private function flowFindings(string $flow, array $group): array
    {
        $summariesByRoute = [];

        foreach (self::CHECKED_FIELDS as $field) {
            $distinct = $this->distinctValues($group, $field);

            if (count($distinct) < 2) {
                continue;
            }

            sort($distinct);
            $summary = "{$field}: ".implode(', ', $distinct);

            foreach ($group as $index => $route) {
                if (! empty($route['route_metadata']['necromancer'][$field] ?? null)) {
                    $summariesByRoute[$index][] = $summary;
                }
            }
        }

        $findings = [];

        foreach ($summariesByRoute as $index => $summaries) {
            $route = $group[$index];

            $findings[] = new Finding(
                severity: 'warning',
                message: "Routes in flow '{$flow}' declare inconsistent ".implode('; ', $summaries),
                artifactType: 'route',
                context: $route['uri'] ?? '',
                source: isset($route['source'])
                    ? ($route['source']['file'] ?? '').':'.($route['source']['line'] ?? '')
                    : null,
            );
        }

        return $findings;
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @return list<string>
     */
    private function distinctValues(array $group, string $field): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $r): string => (string) ($r['route_metadata']['necromancer'][$field] ?? ''),
            $group,
        ), fn (string $v): bool => $v !== '')));
    }
}
