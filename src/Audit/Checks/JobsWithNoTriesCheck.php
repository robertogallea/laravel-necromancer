<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class JobsWithNoTriesCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $jobs = $artifacts['jobs'] ?? [];
        $findings = [];

        foreach ($jobs as $job) {
            $tries = $job['tries'] ?? null;

            if ($tries === null || $tries === 0) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'No retry count defined: '.class_basename((string) ($job['class'] ?? '')),
                    artifactType: 'job',
                    context: $job['class'] ?? '',
                    source: isset($job['source'])
                        ? ($job['source']['file'] ?? '').':'.($job['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($jobs), findings: $findings);
    }
}
