<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Collection\TestSubjectMatcher;

final class ExternalServiceRoutesWithoutTestsCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['routes'] ?? [],
            fn (array $r): bool => ! empty($r['route_metadata']['necromancer']['external_services'] ?? null),
        ));
        $findings = [];

        $testedSubjects = array_filter(
            array_column($artifacts['tests'] ?? [], 'subject'),
            fn (?string $s): bool => $s !== null,
        );

        foreach ($applicable as $route) {
            if (! TestSubjectMatcher::matches((string) ($route['controller'] ?? ''), $testedSubjects)) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'External-service route without matching test evidence: '.($route['method'] ?? '').' '.($route['uri'] ?? ''),
                    artifactType: 'route',
                    context: $route['uri'] ?? '',
                    source: isset($route['source'])
                        ? ($route['source']['file'] ?? '').':'.($route['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }
}
