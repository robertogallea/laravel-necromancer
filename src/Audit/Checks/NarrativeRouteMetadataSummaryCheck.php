<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class NarrativeRouteMetadataSummaryCheck implements CheckInterface
{
    private const SUMMARY_MAX_LENGTH = 200;

    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['routes'] ?? [],
            fn (array $r): bool => ! empty($r['route_metadata']['necromancer']['summary'] ?? null),
        ));
        $findings = [];

        foreach ($applicable as $route) {
            $summary = (string) $route['route_metadata']['necromancer']['summary'];

            if (strlen($summary) > self::SUMMARY_MAX_LENGTH) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'Route metadata summary is too narrative ('.strlen($summary).' chars): '.($route['method'] ?? '').' '.($route['uri'] ?? ''),
                    artifactType: 'route',
                    context: $route['uri'] ?? '',
                    source: isset($route['source'])
                        ? ($route['source']['file'] ?? '').':'.($route['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($applicable), findings: $findings);
    }
}
