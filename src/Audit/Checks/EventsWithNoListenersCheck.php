<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class EventsWithNoListenersCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $events = $artifacts['events'] ?? [];
        $findings = [];

        foreach ($events as $event) {
            if (empty($event['listeners'])) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'No listeners: '.class_basename((string) ($event['class'] ?? '')),
                    artifactType: 'event',
                    context: $event['class'] ?? '',
                    source: isset($event['source'])
                        ? ($event['source']['file'] ?? '').':'.($event['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($events), findings: $findings);
    }
}
