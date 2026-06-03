<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class ModelsWithOpenGuardCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['models'] ?? [],
            fn (array $m) => array_key_exists('guarded', $m),
        ));
        $findings = [];

        foreach ($applicable as $model) {
            if ($model['guarded'] === []) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'Open mass-assignment guard ($guarded = []): '.class_basename((string) ($model['class'] ?? '')),
                    artifactType: 'model',
                    context: $model['class'] ?? '',
                    source: isset($model['source'])
                        ? ($model['source']['file'] ?? '').':'.($model['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($applicable), findings: $findings);
    }
}
