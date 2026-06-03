<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class MissingCastsCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $models = $artifacts['models'] ?? [];
        $findings = [];

        foreach ($models as $model) {
            if (empty($model['casts'])) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'No casts defined: '.class_basename((string) ($model['class'] ?? '')),
                    artifactType: 'model',
                    context: $model['class'] ?? '',
                    source: isset($model['source'])
                        ? ($model['source']['file'] ?? '').':'.($model['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($models), findings: $findings);
    }
}
