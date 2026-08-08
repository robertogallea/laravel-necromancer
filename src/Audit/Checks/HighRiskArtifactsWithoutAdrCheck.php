<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Collection\RouteMetadataNormalizer;
use LaravelNecromancer\Metadata\AnnotatedArtifact;

final class HighRiskArtifactsWithoutAdrCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            AnnotatedArtifact::collect($artifacts),
            fn (AnnotatedArtifact $a): bool => in_array($a->annotations['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true),
        ));
        $findings = [];

        foreach ($applicable as $artifact) {
            if (empty($artifact->annotations['adrs'] ?? null)) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: "High-risk {$artifact->type} without an ADR reference: {$artifact->label}",
                    artifactType: $artifact->type,
                    context: $artifact->label,
                    source: $artifact->source,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }
}
