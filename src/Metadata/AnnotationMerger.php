<?php

declare(strict_types=1);

namespace LaravelNecromancer\Metadata;

/**
 * Merges a less specific set of annotations with a more specific set, following
 * the Annotation Schema v1 precedence rules: a more specific scalar replaces a
 * less specific one, and list fields are combined additively with exact dedupe.
 */
final readonly class AnnotationMerger
{
    /**
     * @return array{0: ArtifactAnnotations, 1: list<string>}
     */
    public function merge(
        ArtifactAnnotations $base,
        ArtifactAnnotations $moreSpecific,
        bool $warnOnConflict = false,
        string $artifactLabel = 'the artifact',
        string $baseSourceLabel = 'the less specific declaration',
        string $moreSpecificSourceLabel = 'the more specific declaration',
    ): array {
        $diagnostics = [];

        [$domain, $domainConflict] = $this->mergeScalar($base->domain, $moreSpecific->domain);
        [$flow, $flowConflict] = $this->mergeScalar($base->flow, $moreSpecific->flow);
        [$capability, $capabilityConflict] = $this->mergeScalar($base->capability, $moreSpecific->capability);
        [$summary, $summaryConflict] = $this->mergeScalar($base->summary, $moreSpecific->summary);
        [$risk, $riskConflict] = $this->mergeScalar($base->risk, $moreSpecific->risk);

        foreach ([
            'domain' => [$domainConflict, $base->domain, $domain],
            'flow' => [$flowConflict, $base->flow, $flow],
            'capability' => [$capabilityConflict, $base->capability, $capability],
            'summary' => [$summaryConflict, $base->summary, $summary],
            'risk' => [$riskConflict, $base->risk?->value, $risk?->value],
        ] as $field => [$conflict, $ignoredValue, $winningValue]) {
            if ($conflict && $warnOnConflict) {
                $diagnostics[] = "AN_SOURCE_CONFLICT: {$artifactLabel} field '{$field}': "
                    ."{$moreSpecificSourceLabel} value '{$winningValue}' overrides "
                    ."{$baseSourceLabel} value '{$ignoredValue}'.";
            }
        }

        $annotations = new ArtifactAnnotations(
            domain: $domain,
            flow: $flow,
            capability: $capability,
            summary: $summary,
            risk: $risk,
            externalServices: $this->mergeList($base->externalServices, $moreSpecific->externalServices),
            adrs: $this->mergeList($base->adrs, $moreSpecific->adrs),
        );

        return [$annotations, $diagnostics];
    }

    /**
     * @template T
     *
     * @param  T|null  $base
     * @param  T|null  $specific
     * @return array{0: T|null, 1: bool}
     */
    private function mergeScalar(mixed $base, mixed $specific): array
    {
        if ($specific !== null) {
            return [$specific, $base !== null && $base !== $specific];
        }

        return [$base, false];
    }

    /**
     * @param  list<string>  $base
     * @param  list<string>  $specific
     * @return list<string>
     */
    private function mergeList(array $base, array $specific): array
    {
        $result = $base;

        foreach ($specific as $value) {
            if (! in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
