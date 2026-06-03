<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class ClosureRoutesCheck implements CheckInterface
{
    public function run(array $artifacts): CheckResult
    {
        $routes = $artifacts['routes'] ?? [];
        $findings = [];

        foreach ($routes as $route) {
            if (empty($route['controller']) && empty($route['action'])) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'Closure route: '.($route['method'] ?? '').' '.($route['uri'] ?? ''),
                    artifactType: 'route',
                    context: $route['uri'] ?? '',
                    source: isset($route['source'])
                        ? ($route['source']['file'] ?? '').':'.($route['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($routes), findings: $findings);
    }
}
