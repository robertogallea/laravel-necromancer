<?php

declare(strict_types=1);

namespace LaravelNecromancer\Audit\Checks;

use LaravelNecromancer\Audit\CheckResult;
use LaravelNecromancer\Audit\Finding;

final class NonGetRoutesWithoutAuthCheck implements CheckInterface
{
    private const AUTH_MIDDLEWARE = ['auth', 'auth:api', 'auth:sanctum', 'sanctum', 'verified'];

    public function run(array $artifacts): CheckResult
    {
        $applicable = array_values(array_filter(
            $artifacts['routes'] ?? [],
            fn (array $r) => ! in_array($r['method'] ?? '', ['GET', 'HEAD'], true),
        ));
        $findings = [];

        foreach ($applicable as $route) {
            $middleware = (array) ($route['middleware'] ?? []);

            if (count(array_intersect($middleware, self::AUTH_MIDDLEWARE)) === 0) {
                $findings[] = new Finding(
                    severity: 'suggestion',
                    message: 'Non-GET route without auth middleware: '.($route['method'] ?? '').' '.($route['uri'] ?? ''),
                    artifactType: 'route',
                    context: $route['uri'] ?? '',
                    source: isset($route['source'])
                        ? ($route['source']['file'] ?? '').':'.($route['source']['line'] ?? '')
                        : null,
                );
            }
        }

        return new CheckResult(severity: 'suggestion', total: count($applicable), findings: $findings);
    }
}
