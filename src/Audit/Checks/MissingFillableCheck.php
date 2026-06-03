<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class MissingFillableCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $models = $artifacts['models'] ?? [];
        $findings = [];

        foreach ($models as $model) {
            if (empty($model['fillable'])) {
                $findings[] = new Finding(
                    severity: 'warning',
                    message: 'No fillable defined: '.class_basename((string) ($model['class'] ?? '')),
                    artifactType: 'model',
                    context: $model['class'] ?? '',
                    source: isset($model['source'])
                        ? ($model['source']['file'] ?? '').':'.($model['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'warning', total: count($models), findings: $findings);
    }
}
