<?php

declare(strict_types=1);

namespace LaravelNecromancer\Doctor;

use LaravelNecromancer\Collection\RouteMetadataNormalizer;
use LaravelNecromancer\Collection\TestSubjectMatcher;

final class DoctorAnalyzer
{
    /**
     * @param  array<string, mixed>  $artifacts
     */
    public function __construct(private readonly array $artifacts) {}

    /**
     * @return DimensionResult[]
     */
    public function dimensions(): array
    {
        return [
            $this->routeClarity(),
            $this->modelExpressiveness(),
            $this->authorizationCoverage(),
            $this->validationCoverage(),
            $this->asyncClarity(),
            $this->codebaseVocabulary(),
            $this->testPresence(),
            $this->routeMetadataCoverage(),
        ];
    }

    /**
     * @param  DimensionResult[]  $dimensions
     */
    public function overallScore(array $dimensions): int
    {
        if (empty($dimensions)) {
            return 100;
        }

        $totalWeight = array_sum(array_map(fn (DimensionResult $d): float => $d->weight, $dimensions));
        $weightedSum = array_sum(array_map(fn (DimensionResult $d): float => $d->score * $d->weight, $dimensions));

        return $totalWeight > 0.0 ? (int) round(($weightedSum / $totalWeight) * 100) : 100;
    }

    private function routeClarity(): DimensionResult
    {
        $routes = (array) ($this->artifacts['routes'] ?? []);
        $total = count($routes);

        if ($total === 0) {
            return new DimensionResult('route-clarity', 'Route Clarity', 1.0, 'N/A', 0.20);
        }

        $named = count(array_filter($routes, fn (array $r): bool => ! empty($r['name'])));
        $controllerBacked = count(array_filter($routes, fn (array $r): bool => ! empty($r['controller'])));

        $score = (($named / $total) + ($controllerBacked / $total)) / 2;
        $detail = "{$named}/{$total} named · {$controllerBacked}/{$total} controller-backed";

        return new DimensionResult('route-clarity', 'Route Clarity', $score, $detail, 0.20);
    }

    private function modelExpressiveness(): DimensionResult
    {
        $models = (array) ($this->artifacts['models'] ?? []);
        $total = count($models);

        if ($total === 0) {
            return new DimensionResult('model-expressiveness', 'Model Expressiveness', 1.0, 'N/A', 0.20);
        }

        $withCasts = count(array_filter($models, fn (array $m): bool => ! empty($m['casts'])));
        $withFillable = count(array_filter($models, fn (array $m): bool => ! empty($m['fillable']) || (array_key_exists('guarded', $m) && $m['guarded'] !== [] && $m['guarded'] !== ['*'])));
        $withRelationships = count(array_filter($models, fn (array $m): bool => ! empty($m['relationships'])));

        $score = (($withCasts / $total) + ($withFillable / $total) + ($withRelationships / $total)) / 3;
        $detail = "{$withCasts}/{$total} casts · {$withFillable}/{$total} fillable · {$withRelationships}/{$total} relationships";

        return new DimensionResult('model-expressiveness', 'Model Expressiveness', $score, $detail, 0.20);
    }

    private function authorizationCoverage(): DimensionResult
    {
        $models = (array) ($this->artifacts['models'] ?? []);
        $policies = (array) ($this->artifacts['policies'] ?? []);
        $routes = (array) ($this->artifacts['routes'] ?? []);

        $modelTotal = count($models);
        $writeRoutes = array_values(array_filter($routes, fn (array $r): bool => in_array(strtoupper((string) ($r['method'] ?? '')), ['POST', 'PUT', 'PATCH', 'DELETE'], true)));
        $writeTotal = count($writeRoutes);

        if ($modelTotal === 0 && $writeTotal === 0) {
            return new DimensionResult('authorization-coverage', 'Authorization Coverage', 1.0, 'N/A', 0.15);
        }

        $policyModels = array_map(fn (array $p): string => (string) ($p['model'] ?? ''), $policies);
        $modelsWithPolicy = $modelTotal > 0
            ? count(array_filter($models, fn (array $m): bool => in_array($m['class'] ?? '', $policyModels, true)))
            : 0;

        $authMiddleware = ['auth', 'auth:api', 'auth:sanctum', 'sanctum', 'verified'];
        $writeRoutesWithAuth = $writeTotal > 0
            ? count(array_filter($writeRoutes, fn (array $r): bool => count(array_intersect((array) ($r['middleware'] ?? []), $authMiddleware)) > 0))
            : 0;

        $ratios = [];

        if ($modelTotal > 0) {
            $ratios[] = $modelsWithPolicy / $modelTotal;
        }

        if ($writeTotal > 0) {
            $ratios[] = $writeRoutesWithAuth / $writeTotal;
        }

        $score = array_sum($ratios) / count($ratios);

        $detailParts = [];
        if ($modelTotal > 0) {
            $detailParts[] = "{$modelsWithPolicy}/{$modelTotal} policies";
        }
        if ($writeTotal > 0) {
            $detailParts[] = "{$writeRoutesWithAuth}/{$writeTotal} write routes with auth";
        }

        return new DimensionResult('authorization-coverage', 'Authorization Coverage', $score, implode(' · ', $detailParts), 0.15);
    }

    private function validationCoverage(): DimensionResult
    {
        $routes = (array) ($this->artifacts['routes'] ?? []);
        $requests = (array) ($this->artifacts['form_requests'] ?? []);

        $writeRoutes = array_values(array_filter($routes, fn (array $r): bool => in_array(strtoupper((string) ($r['method'] ?? '')), ['POST', 'PUT', 'PATCH'], true)));
        $writeTotal = count($writeRoutes);

        if ($writeTotal === 0) {
            return new DimensionResult('validation-coverage', 'Validation Coverage', 1.0, 'N/A', 0.15);
        }

        $requestCount = count($requests);
        $covered = min($requestCount, $writeTotal);
        $score = $covered / $writeTotal;
        $detail = "{$covered}/{$writeTotal} write routes with FormRequest";

        return new DimensionResult('validation-coverage', 'Validation Coverage', $score, $detail, 0.15);
    }

    private function asyncClarity(): DimensionResult
    {
        $jobs = (array) ($this->artifacts['jobs'] ?? []);
        $events = (array) ($this->artifacts['events'] ?? []);

        $jobTotal = count($jobs);
        $eventTotal = count($events);

        if ($jobTotal === 0 && $eventTotal === 0) {
            return new DimensionResult('async-clarity', 'Async Clarity', 1.0, 'N/A', 0.15);
        }

        $jobClaritySum = 0.0;
        foreach ($jobs as $j) {
            $criteria = [
                ! empty($j['queue']),
                ($j['tries'] ?? null) !== null,
                ($j['timeout'] ?? null) !== null,
                ($j['backoff'] ?? null) !== null,
            ];
            $jobClaritySum += count(array_filter($criteria)) / count($criteria);
        }

        $eventsWithListeners = $eventTotal > 0
            ? count(array_filter($events, fn (array $e): bool => ! empty($e['listeners'])))
            : 0;

        $ratios = [];

        if ($jobTotal > 0) {
            $ratios[] = $jobClaritySum / $jobTotal;
        }

        if ($eventTotal > 0) {
            $ratios[] = $eventsWithListeners / $eventTotal;
        }

        $score = array_sum($ratios) / count($ratios);

        $detailParts = [];
        if ($jobTotal > 0) {
            $configuredJobs = (int) round($jobClaritySum);
            $detailParts[] = "{$configuredJobs}/{$jobTotal} jobs configured";
        }
        if ($eventTotal > 0) {
            $detailParts[] = "{$eventsWithListeners}/{$eventTotal} events with listeners";
        }

        return new DimensionResult('async-clarity', 'Async Clarity', $score, implode(' · ', $detailParts), 0.15);
    }

    private function codebaseVocabulary(): DimensionResult
    {
        $commands = (array) ($this->artifacts['commands'] ?? []);
        $enums = (array) ($this->artifacts['enums'] ?? []);

        $commandTotal = count($commands);
        $enumTotal = count($enums);

        if ($commandTotal === 0 && $enumTotal === 0) {
            return new DimensionResult('codebase-vocabulary', 'Codebase Vocabulary', 1.0, 'N/A', 0.15);
        }

        $describedCommands = $commandTotal > 0
            ? count(array_filter($commands, fn (array $c): bool => ! empty($c['description'])))
            : 0;

        $backedEnums = $enumTotal > 0
            ? count(array_filter($enums, fn (array $e): bool => ! empty($e['backing_type'])))
            : 0;

        $ratios = [];

        if ($commandTotal > 0) {
            $ratios[] = $describedCommands / $commandTotal;
        }

        if ($enumTotal > 0) {
            $ratios[] = $backedEnums / $enumTotal;
        }

        $score = array_sum($ratios) / count($ratios);

        $detailParts = [];
        if ($commandTotal > 0) {
            $detailParts[] = "{$describedCommands}/{$commandTotal} commands described";
        }
        if ($enumTotal > 0) {
            $detailParts[] = "{$backedEnums}/{$enumTotal} backed enums";
        }

        return new DimensionResult('codebase-vocabulary', 'Codebase Vocabulary', $score, implode(' · ', $detailParts), 0.15);
    }

    private function testPresence(): DimensionResult
    {
        $tests = (array) ($this->artifacts['tests'] ?? []);
        $models = (array) ($this->artifacts['models'] ?? []);
        $jobs = (array) ($this->artifacts['jobs'] ?? []);

        $modelTotal = count($models);
        $jobTotal = count($jobs);
        $testsScanned = array_key_exists('tests', $this->artifacts);

        if (! $testsScanned || ($modelTotal === 0 && $jobTotal === 0)) {
            return new DimensionResult('test-presence', 'Test Presence', 1.0, 'N/A', 0.10);
        }

        $testedSubjects = array_filter(
            array_column($tests, 'subject'),
            fn (?string $s): bool => $s !== null,
        );

        $ratios = [];
        $detailParts = [];

        if ($modelTotal > 0) {
            $modelsWithTests = count(array_filter(
                $models,
                fn (array $m): bool => TestSubjectMatcher::matches($m['class'] ?? '', $testedSubjects),
            ));
            $ratios[] = $modelsWithTests / $modelTotal;
            $detailParts[] = "{$modelsWithTests}/{$modelTotal} models";
        }

        if ($jobTotal > 0) {
            $jobsWithTests = count(array_filter(
                $jobs,
                fn (array $j): bool => TestSubjectMatcher::matches($j['class'] ?? '', $testedSubjects),
            ));
            $ratios[] = $jobsWithTests / $jobTotal;
            $detailParts[] = "{$jobsWithTests}/{$jobTotal} jobs";
        }

        $score = array_sum($ratios) / count($ratios);

        return new DimensionResult('test-presence', 'Test Presence', $score, implode(' · ', $detailParts), 0.10);
    }

    private function routeMetadataCoverage(): DimensionResult
    {
        $routes = (array) ($this->artifacts['routes'] ?? []);

        $annotated = array_values(array_filter(
            $routes,
            fn (array $r): bool => ! empty($r['route_metadata']['necromancer'] ?? null),
        ));
        $annotatedTotal = count($annotated);

        if ($annotatedTotal === 0) {
            return new DimensionResult('route-metadata-coverage', 'Route Metadata Coverage', 1.0, 'N/A', 0.10);
        }

        $withDomain = count(array_filter(
            $annotated,
            fn (array $r): bool => ! empty($r['route_metadata']['necromancer']['domain'] ?? null),
        ));

        $highRisk = array_values(array_filter(
            $annotated,
            fn (array $r): bool => in_array($r['route_metadata']['necromancer']['risk'] ?? null, RouteMetadataNormalizer::HIGH_RISK_LEVELS, true),
        ));
        $highRiskTotal = count($highRisk);
        $highRiskWithAdr = $highRiskTotal > 0
            ? count(array_filter($highRisk, fn (array $r): bool => ! empty($r['route_metadata']['necromancer']['adr'] ?? null)))
            : 0;

        $externalService = array_values(array_filter(
            $annotated,
            fn (array $r): bool => ! empty($r['route_metadata']['necromancer']['external_services'] ?? null),
        ));
        $externalServiceTotal = count($externalService);

        $testedSubjects = array_filter(
            array_column((array) ($this->artifacts['tests'] ?? []), 'subject'),
            fn (?string $s): bool => $s !== null,
        );
        $externalServiceTested = $externalServiceTotal > 0
            ? count(array_filter($externalService, fn (array $r): bool => TestSubjectMatcher::matches((string) ($r['controller'] ?? ''), $testedSubjects)))
            : 0;

        $ratios = [$withDomain / $annotatedTotal];
        $detailParts = ["{$withDomain}/{$annotatedTotal} tagged with domain"];

        if ($highRiskTotal > 0) {
            $ratios[] = $highRiskWithAdr / $highRiskTotal;
            $detailParts[] = "{$highRiskWithAdr}/{$highRiskTotal} high-risk with ADR";
        }

        if ($externalServiceTotal > 0) {
            $ratios[] = $externalServiceTested / $externalServiceTotal;
            $detailParts[] = "{$externalServiceTested}/{$externalServiceTotal} external-service routes tested";
        }

        $byFlow = [];
        foreach ($annotated as $route) {
            $flow = $route['route_metadata']['necromancer']['flow'] ?? null;

            if (! empty($flow)) {
                $byFlow[$flow][] = $route;
            }
        }

        $groupedTotal = 0;
        $consistentCount = 0;

        foreach ($byFlow as $group) {
            if (count($group) < 2) {
                continue;
            }

            $groupedTotal += count($group);

            $conflict = $this->hasFieldConflict($group, 'domain') || $this->hasFieldConflict($group, 'risk');

            if (! $conflict) {
                $consistentCount += count($group);
            }
        }

        if ($groupedTotal > 0) {
            $ratios[] = $consistentCount / $groupedTotal;
            $detailParts[] = "{$consistentCount}/{$groupedTotal} flow-consistent";
        }

        $score = array_sum($ratios) / count($ratios);

        return new DimensionResult('route-metadata-coverage', 'Route Metadata Coverage', $score, implode(' · ', $detailParts), 0.10);
    }

    /**
     * @param  list<array<string, mixed>>  $group
     */
    private function hasFieldConflict(array $group, string $field): bool
    {
        $distinct = array_unique(array_filter(array_map(
            fn (array $r): ?string => $r['route_metadata']['necromancer'][$field] ?? null,
            $group,
        )));

        return count($distinct) > 1;
    }
}
