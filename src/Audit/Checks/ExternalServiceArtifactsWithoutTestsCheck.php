<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Collection\TestSubjectMatcher;
use LaravelNecromancer\Metadata\AnnotatedArtifact;

final class ExternalServiceArtifactsWithoutTestsCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            AnnotatedArtifact::collect($artifacts),
            fn (AnnotatedArtifact $a): bool => ! empty($a->annotations['external_services'] ?? null),
        ));
        $findings = [];

        $testedSubjects = array_filter(
            array_column((array) ($artifacts['tests'] ?? []), 'subject'),
            fn (?string $s): bool => $s !== null,
        );

        foreach ($applicable as $artifact) {
            $matched = $artifact->subject !== null && TestSubjectMatcher::matches($artifact->subject, $testedSubjects);

            if (! $matched) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: "External-service {$artifact->type} without matching test evidence: {$artifact->label}",
                    artifactType: $artifact->type,
                    context: $artifact->label,
                    source: $artifact->source,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }
}
