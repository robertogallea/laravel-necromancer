<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class JobsWithNoTimeoutCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['jobs'] ?? [],
            fn (array $j) => array_key_exists('timeout', $j),
        ));
        $findings = [];

        foreach ($applicable as $job) {
            if ($job['timeout'] === null) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'No timeout defined: '.class_basename((string) ($job['class'] ?? '')),
                    artifactType: 'job',
                    context: $job['class'] ?? '',
                    source: isset($job['source'])
                        ? ($job['source']['file'] ?? '').':'.($job['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }
}
