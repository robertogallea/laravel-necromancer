<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Metadata\AnnotatedArtifact;

final class NarrativeAnnotationSummaryCheck implements CheckInterface
{
    private const SUMMARY_MAX_LENGTH = 200;

    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            AnnotatedArtifact::collect($artifacts),
            fn (AnnotatedArtifact $a): bool => ! empty($a->annotations['summary'] ?? null),
        ));
        $findings = [];

        foreach ($applicable as $artifact) {
            $summary = (string) $artifact->annotations['summary'];

            if (strlen($summary) > self::SUMMARY_MAX_LENGTH) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'Annotation summary is too narrative ('.strlen($summary)." chars) on {$artifact->type}: {$artifact->label}",
                    artifactType: $artifact->type,
                    context: $artifact->label,
                    source: $artifact->source,
                );
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($applicable), findings: $findings);
    }
}
