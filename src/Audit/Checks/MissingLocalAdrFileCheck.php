<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;
use LaravelNecromancer\Metadata\AnnotatedArtifact;

final class MissingLocalAdrFileCheck implements CheckInterface
{
    public function __construct(private readonly string $basePath) {}

    public function run(array $artifacts): CheckResult
    {
        $applicable = 0;
        $findings = [];

        foreach (AnnotatedArtifact::collect($artifacts) as $artifact) {
            foreach ((array) ($artifact->annotations['adrs'] ?? []) as $adr) {
                if (! is_string($adr) || $adr === '' || $this->isAbsoluteUri($adr)) {
                    continue;
                }

                $applicable++;

                if (! is_file($this->basePath.DIRECTORY_SEPARATOR.ltrim($adr, '/\\'))) {
                    $findings[] = new Finding(
                        severity: 'warning',
                        message: "Missing local ADR file '{$adr}' referenced by {$artifact->type}: {$artifact->label}",
                        artifactType: $artifact->type,
                        context: $artifact->label,
                        source: $artifact->source,
                    );
                }
            }
        }

        return new CheckResult(severity: 'warning', total: $applicable, findings: $findings);
    }

    private function isAbsoluteUri(string $value): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value);
    }
}
