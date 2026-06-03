<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class EmptyCommandDescriptionsCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $commands = $artifacts['commands'] ?? [];
        $findings = [];

        foreach ($commands as $command) {
            if (empty($command['description'])) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'Empty description: '.($command['signature'] ?? ''),
                    artifactType: 'command',
                    context: $command['class'] ?? '',
                    source: isset($command['source'])
                        ? ($command['source']['file'] ?? '').':'.($command['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($commands), findings: $findings);
    }
}
