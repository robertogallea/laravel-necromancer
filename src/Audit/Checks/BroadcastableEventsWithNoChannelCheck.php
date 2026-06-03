<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class BroadcastableEventsWithNoChannelCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['events'] ?? [],
            fn (array $e) => ($e['broadcastable'] ?? false) === true,
        ));
        $findings = [];

        foreach ($applicable as $event) {
            if (empty($event['channels'])) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'Broadcastable event with no channel defined: '.class_basename((string) ($event['class'] ?? '')),
                    artifactType: 'event',
                    context: $event['class'] ?? '',
                    source: isset($event['source'])
                        ? ($event['source']['file'] ?? '').':'.($event['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }
}
