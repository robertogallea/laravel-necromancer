<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Collection\RouteMetadataNormalizer;

final class HighRiskRoutesWithoutAdrCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['routes'] ?? [],
            fn (array $r): bool => in_array($r['route_metadata']['necromancer']['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true),
        ));
        $findings = [];

        foreach ($applicable as $route) {
            if (empty($route['route_metadata']['necromancer']['adr'] ?? null)) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'High-risk route without an ADR reference: '.($route['method'] ?? '').' '.($route['uri'] ?? ''),
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
