<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class JobsWithNoQueueNameCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $jobs = $artifacts['jobs'] ?? [];
        $findings = [];

        foreach ($jobs as $job) {
            if (empty($job['queue'])) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'No queue name: '.class_basename((string) ($job['class'] ?? '')),
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
