<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Integrations\BoostDetector;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class GenerateCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:generate
        {--output= : Override the Tier 2 output file path}
        {--force : Overwrite existing Tier 2 file without confirmation}
        {--only= : Comma-separated artifact type(s) to include (routes, models, jobs, events, listeners, commands)}
        {--except= : Comma-separated artifact type(s) to exclude (routes, models, form_requests, jobs, events, listeners, commands, policies, enums)}';

    /** @var array<int, string> */
    private const SUPPORTED_TYPES = ['routes', 'models', 'form_requests', 'jobs', 'events', 'listeners', 'commands', 'observers', 'policies', 'enums', 'tests', 'scheduled_tasks', 'middleware'];

    protected $description = 'Generate AI-readable context from the Necromancer manifest';

    public function handle(ManifestReader $reader, BoostDetector $boostDetector): int
    {
        $manifestPath = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($manifestPath);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        if (filled($this->option('only')) && filled($this->option('except'))) {
            $this->error('Cannot use --only and --except together.');

            return self::FAILURE;
        }

        $onlyTypes = null;

        if (filled($this->option('only'))) {
            $requested = array_unique(array_filter(array_map('trim', explode(',', $this->option('only')))));
            $invalid = array_values(array_diff($requested, self::SUPPORTED_TYPES));

            if (! empty($invalid)) {
                $this->error(
                    'Unknown type(s): '.implode(', ', $invalid).'. Available types: '.implode(', ', self::SUPPORTED_TYPES)
                );

                return self::FAILURE;
            }

            $onlyTypes = $requested;
        }

        $exceptTypes = null;

        if (filled($this->option('except'))) {
            $requested = array_unique(array_filter(array_map('trim', explode(',', $this->option('except')))));
            $invalid = array_values(array_diff($requested, self::SUPPORTED_TYPES));

            if (! empty($invalid)) {
                $this->error(
                    'Unknown type(s): '.implode(', ', $invalid).'. Available types: '.implode(', ', self::SUPPORTED_TYPES)
                );

                return self::FAILURE;
            }

            $exceptTypes = $requested;
        }

        $usingBoost = $boostDetector->isAvailable();

        // --- Tier 2: full content with --only/--except filtering ---
        $sectionMap = [
            'overview' => $this->buildOverview($manifest['meta'] ?? []),
            'routes' => $this->buildRoutes($manifest['artifacts']['routes'] ?? []),
            'models' => $this->buildModels($manifest['artifacts']['models'] ?? []),
            'form_requests' => $this->buildFormRequests($manifest['artifacts']['form_requests'] ?? []),
            'jobs' => $this->buildJobs($manifest['artifacts']['jobs'] ?? []),
            'events' => $this->buildEvents($manifest['artifacts']['events'] ?? []),
            'listeners' => $this->buildListeners($manifest['artifacts']['listeners'] ?? []),
            'commands' => $this->buildCommands($manifest['artifacts']['commands'] ?? []),
            'observers' => $this->buildObservers($manifest['artifacts']['observers'] ?? []),
            'policies' => $this->buildPolicies($manifest['artifacts']['policies'] ?? []),
            'enums' => $this->buildEnums($manifest['artifacts']['enums'] ?? []),
            'tests' => $this->buildTests($manifest['artifacts']['tests'] ?? []),
            'scheduled_tasks' => $this->buildScheduledTasks($manifest['artifacts']['scheduled_tasks'] ?? []),
            'middleware' => $this->buildMiddleware($manifest['artifacts']['middleware'] ?? []),
        ];

        if ($onlyTypes !== null) {
            $sectionMap = array_filter(
                $sectionMap,
                fn (string $key) => $key === 'overview' || in_array($key, $onlyTypes, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if ($exceptTypes !== null) {
            $sectionMap = array_filter(
                $sectionMap,
                fn (string $key) => $key === 'overview' || ! in_array($key, $exceptTypes, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        $writtenNames = array_keys(array_filter($sectionMap));
        $tier2Content = implode("\n\n", array_filter($sectionMap));

        // --- Tier 1: compact content (fixed set, not affected by --only/--except) ---
        $tier1Content = $this->buildTier1($manifest)."\n\n".$this->buildTier1Footer($usingBoost);

        // --- Resolve Tier 2 path ---
        $override = $this->option('output');

        if (is_string($override) && $override !== '') {
            $tier2Path = $this->isAbsolutePath($override) ? $override : base_path($override);
        } elseif ($usingBoost) {
            $tier2Path = $this->resolveSkillPath();
        } else {
            $path = (string) config('necromancer.output.context', base_path('NECROMANCER.md'));
            $tier2Path = $this->isAbsolutePath($path) ? $path : base_path($path);
        }

        // --- Resolve Tier 1 path ---
        if ($usingBoost) {
            $tier1Path = $this->resolveContextPath();
            $configuredBoostPath = (string) config('necromancer.boost.context_path', base_path('.ai/context/necromancer.md'));
            $resolvedBoostPath = $this->isAbsolutePath($configuredBoostPath) ? $configuredBoostPath : base_path($configuredBoostPath);
            $tier1UsingBoostPath = ($tier1Path === $resolvedBoostPath);
        } else {
            $claudePath = (string) config('necromancer.output.claude', base_path('CLAUDE.md'));
            $tier1Path = $this->isAbsolutePath($claudePath) ? $claudePath : base_path($claudePath);
            $tier1UsingBoostPath = false;
        }

        // --- Overwrite confirmation for Tier 2 ---
        if (file_exists($tier2Path) && ! $this->option('force')) {
            if (! $this->confirm(basename($tier2Path).' already exists. Overwrite?')) {
                return self::FAILURE;
            }
        }

        // --- Write Tier 2 ---
        file_put_contents($tier2Path, $tier2Content);
        $this->line("Full context written to {$tier2Path}");

        // --- Write Tier 1 ---
        if ($usingBoost && $tier1UsingBoostPath) {
            file_put_contents($tier1Path, $tier1Content);
            $this->line("Compact context written to {$tier1Path}");
            $this->line('Laravel Boost is responsible for composing the final agent context.');
            $this->line('Run `php artisan boost:update` to refresh your agent context.');
        } else {
            if ($usingBoost) {
                // Boost context dir creation failed; Tier 1 falls back to standalone behaviour
                file_put_contents($tier1Path, $tier1Content);
            } else {
                $this->writeToClaude($tier1Content, $tier1Path);

                $agentsPath = (string) config('necromancer.output.agents', base_path('AGENTS.md'));
                $agentsPath = $this->isAbsolutePath($agentsPath) ? $agentsPath : base_path($agentsPath);
                $this->writeToClaude($tier1Content, $agentsPath);
            }
            $this->line("Compact context written to {$tier1Path}");
            $this->line('Sections: '.implode(', ', $writtenNames));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function buildOverview(array $meta): string
    {
        $lines = [
            '[//]: # (generated by Laravel Necromancer — do not edit manually)',
            '[//]: # (run `php artisan necromancer:generate` to refresh)',
            '',
        ];

        $appName = isset($meta['app_name']) ? (string) $meta['app_name'] : 'Application';
        $lines[] = "# {$appName} — AI Context";
        $lines[] = '';
        $lines[] = '## Application';
        $lines[] = '';

        $hasLaravel = isset($meta['laravel_version']);
        $hasPHP = isset($meta['php_version']);

        if ($hasLaravel && $hasPHP) {
            $lines[] = "- **Laravel**: {$meta['laravel_version']} · **PHP**: {$meta['php_version']}";
        } elseif ($hasLaravel) {
            $lines[] = "- **Laravel**: {$meta['laravel_version']}";
        } elseif ($hasPHP) {
            $lines[] = "- **PHP**: {$meta['php_version']}";
        }

        if (isset($meta['app_url'])) {
            $lines[] = "- **URL**: {$meta['app_url']}";
        }

        if (isset($meta['app_env'])) {
            $lines[] = "- **Environment**: {$meta['app_env']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     */
    private function buildRoutes(array $routes): string
    {
        if (empty($routes)) {
            return '';
        }

        $count = count($routes);
        $hasParameters = ! empty(array_filter($routes, fn (array $r): bool => ! empty($r['parameters'])));
        $hasAuthorization = ! empty(array_filter($routes, fn (array $r): bool => ! empty($r['authorization'])));
        $hasSources = $this->hasSources($routes);

        $header = '| Name | Method | URI | Controller | Middleware';
        $divider = '|---|---|---|---|---';
        if ($hasParameters) {
            $header .= ' | Parameters';
            $divider .= '|---';
        }
        if ($hasAuthorization) {
            $header .= ' | Authorization';
            $divider .= '|---';
        }
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Routes ({$count})", '', $header.' |', $divider.'|'];

        foreach ($routes as $route) {
            $name = (string) ($route['name'] ?? '');
            $method = (string) ($route['method'] ?? '');
            $uri = (string) ($route['uri'] ?? '');
            $controller = $this->formatControllerAction($route);
            $middleware = implode(', ', $route['middleware'] ?? []);

            $row = "| {$name} | {$method} | {$uri} | {$controller} | {$middleware}";

            if ($hasParameters) {
                $row .= ' | '.$this->formatParameters($route['parameters'] ?? []);
            }
            if ($hasAuthorization) {
                $abilities = array_map(
                    fn (array $auth): string => (string) ($auth['ability'] ?? ''),
                    $route['authorization'] ?? [],
                );
                $row .= ' | '.implode(', ', array_filter($abilities));
            }
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($route);
            }

            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     */
    private function buildCompactRoutes(array $routes): string
    {
        if (empty($routes)) {
            return '';
        }

        $count = count($routes);
        $prefixCounts = [];

        foreach ($routes as $route) {
            $uri = (string) ($route['uri'] ?? '');
            $firstSegment = explode('/', ltrim($uri, '/'))[0];
            $prefix = $firstSegment !== '' ? '/'.$firstSegment : '/';
            $prefixCounts[$prefix] = ($prefixCounts[$prefix] ?? 0) + 1;
        }

        $lines = ["## Routes ({$count})", ''];
        $lines[] = '| Prefix | Routes |';
        $lines[] = '|---|---|';

        foreach ($prefixCounts as $prefix => $routeCount) {
            $lines[] = "| {$prefix} | {$routeCount} |";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     */
    private function buildModels(array $models): string
    {
        if (empty($models)) {
            return '';
        }

        $count = count($models);
        $hasSources = $this->hasSources($models);

        $header = '| Name | Table | Fillable | Casts | Relationships | Soft Deletes';
        $divider = '|---|---|---|---|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Models ({$count})", '', $header.' |', $divider.'|'];

        foreach ($models as $model) {
            $basename = class_basename((string) ($model['class'] ?? ''));
            $table = (string) ($model['table'] ?? '');
            $fillable = implode(', ', $model['fillable'] ?? []);

            $castPairs = [];
            foreach ($model['casts'] ?? [] as $field => $cast) {
                $castPairs[] = "{$field} → {$cast}";
            }
            $casts = implode(', ', $castPairs);

            $rels = array_map(
                fn (array $rel) => ($rel['type'] ?? '').' '.class_basename((string) ($rel['related'] ?? '')),
                $model['relationships'] ?? [],
            );
            $relationships = implode(', ', $rels);

            $extras = [];
            if (! empty($model['hidden'])) {
                $extras[] = 'hidden: '.implode(', ', $model['hidden']);
            }
            if (! empty($model['scopes'])) {
                $extras[] = 'scopes: '.implode(', ', $model['scopes']);
            }
            if (! empty($model['observers'])) {
                $observerNames = array_map(fn (string $o) => class_basename($o), $model['observers']);
                $extras[] = 'observers: '.implode(', ', $observerNames);
            }
            if (! empty($model['global_scopes'])) {
                $scopeNames = array_map(fn (string $s) => class_basename($s), $model['global_scopes']);
                $extras[] = 'global_scopes: '.implode(', ', $scopeNames);
            }
            if (! empty($model['policy'])) {
                $extras[] = 'policy: '.class_basename((string) $model['policy']);
            }
            if (! empty($model['factory'])) {
                $extras[] = 'factory: '.class_basename((string) $model['factory']);
            }
            if (! empty($model['custom_builder'])) {
                $extras[] = 'builder: '.class_basename((string) $model['custom_builder']);
            }
            $softDeletes = ! empty($model['soft_deletes']) ? 'yes' : '';

            if ($extras) {
                $relationships .= ($relationships ? '; ' : '').implode('; ', $extras);
            }

            $row = "| {$basename} | {$table} | {$fillable} | {$casts} | {$relationships} | {$softDeletes}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($model);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $models
     */
    private function buildCompactModels(array $models): string
    {
        if (empty($models)) {
            return '';
        }

        $count = count($models);
        $lines = ["## Models ({$count})", ''];
        $lines[] = '| Model | Table | Relationships |';
        $lines[] = '|---|---|---|';

        foreach ($models as $model) {
            $basename = class_basename((string) ($model['class'] ?? ''));
            $table = (string) ($model['table'] ?? '');

            $rels = array_map(
                fn (array $rel) => ($rel['type'] ?? '').' '.class_basename((string) ($rel['related'] ?? '')),
                $model['relationships'] ?? [],
            );
            $relationships = implode(', ', $rels);

            $lines[] = "| {$basename} | {$table} | {$relationships} |";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function buildTier1(array $manifest): string
    {
        return implode("\n\n", array_filter([
            $this->buildOverview($manifest['meta'] ?? []),
            $this->buildCompactRoutes($manifest['artifacts']['routes'] ?? []),
            $this->buildCompactModels($manifest['artifacts']['models'] ?? []),
        ]));
    }

    private function buildTier1Footer(bool $usingBoost): string
    {
        if ($usingBoost) {
            return '> For complete application context (all route details, model fields, casts, jobs, events, listeners, commands, policies, form requests, enums), use the `necromancer` skill.';
        }

        return '> For complete application context (all route details, model fields, casts, jobs, events, listeners, commands, policies, form requests, enums), read `NECROMANCER.md`.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $formRequests
     */
    private function buildFormRequests(array $formRequests): string
    {
        if (empty($formRequests)) {
            return '';
        }

        $count = count($formRequests);
        $hasSources = $this->hasSources($formRequests);
        $hasStopOnFirstFailure = ! empty(array_filter($formRequests, fn (array $r): bool => isset($r['stop_on_first_failure'])));
        $hasErrorBag = ! empty(array_filter($formRequests, fn (array $r): bool => isset($r['error_bag']) && $r['error_bag'] !== ''));

        $header = '| Class | Rules';
        $divider = '|---|---';
        if ($hasStopOnFirstFailure) {
            $header .= ' | stop_on_first_failure';
            $divider .= '|---';
        }
        if ($hasErrorBag) {
            $header .= ' | error_bag';
            $divider .= '|---';
        }
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Form Requests ({$count})", '', $header.' |', $divider.'|'];

        foreach ($formRequests as $request) {
            $basename = class_basename((string) ($request['class'] ?? ''));
            $ruleParts = [];

            foreach ($request['rules'] ?? [] as $field => $rule) {
                $ruleParts[] = "{$field}: {$rule}";
            }

            $rules = implode(', ', $ruleParts);
            $row = "| {$basename} | {$rules}";
            if ($hasStopOnFirstFailure) {
                $stop = isset($request['stop_on_first_failure']) ? ($request['stop_on_first_failure'] ? 'yes' : 'no') : '';
                $row .= " | {$stop}";
            }
            if ($hasErrorBag) {
                $errorBag = (string) ($request['error_bag'] ?? '');
                $row .= " | {$errorBag}";
            }
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($request);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     */
    private function buildJobs(array $jobs): string
    {
        if (empty($jobs)) {
            return '';
        }

        $count = count($jobs);
        $hasSources = $this->hasSources($jobs);
        $hasBackoff = ! empty(array_filter($jobs, fn (array $j): bool => ! empty($j['backoff'])));
        $hasMaxExceptions = ! empty(array_filter($jobs, fn (array $j): bool => isset($j['max_exceptions'])));

        $header = '| Name | Queue | Connection | Tries';
        $divider = '|---|---|---|---';
        if ($hasBackoff) {
            $header .= ' | backoff';
            $divider .= '|---';
        }
        if ($hasMaxExceptions) {
            $header .= ' | max_exceptions';
            $divider .= '|---';
        }
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Jobs ({$count})", '', $header.' |', $divider.'|'];

        foreach ($jobs as $job) {
            $basename = class_basename((string) ($job['class'] ?? ''));
            $queue = (string) ($job['queue'] ?? '');
            $connection = (string) ($job['connection'] ?? '');
            $tries = isset($job['tries']) ? (string) $job['tries'] : '';
            $row = "| {$basename} | {$queue} | {$connection} | {$tries}";
            if ($hasBackoff) {
                $backoff = isset($job['backoff'])
                    ? (is_array($job['backoff']) ? implode(', ', $job['backoff']) : (string) $job['backoff'])
                    : '';
                $row .= " | {$backoff}";
            }
            if ($hasMaxExceptions) {
                $maxExceptions = isset($job['max_exceptions']) ? (string) $job['max_exceptions'] : '';
                $row .= " | {$maxExceptions}";
            }
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($job);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function buildEvents(array $events): string
    {
        if (empty($events)) {
            return '';
        }

        $count = count($events);
        $hasSources = $this->hasSources($events);

        $header = '| Name | Listeners';
        $divider = '|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Events ({$count})", '', $header.' |', $divider.'|'];

        foreach ($events as $event) {
            $basename = class_basename((string) ($event['class'] ?? ''));
            $listenerNames = array_map(
                fn (string $l) => class_basename($l),
                $event['listeners'] ?? [],
            );
            $listeners = implode(', ', $listenerNames);
            $row = "| {$basename} | {$listeners}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($event);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $listeners
     */
    private function buildListeners(array $listeners): string
    {
        if (empty($listeners)) {
            return '';
        }

        $count = count($listeners);
        $hasSources = $this->hasSources($listeners);

        $header = '| Name | Handles | Queued';
        $divider = '|---|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Listeners ({$count})", '', $header.' |', $divider.'|'];

        foreach ($listeners as $listener) {
            $basename = class_basename((string) ($listener['class'] ?? ''));
            $eventNames = array_map(
                fn (string $e) => class_basename($e),
                $listener['handles'] ?? [],
            );
            $handles = implode(', ', $eventNames);
            $queued = (bool) ($listener['queued'] ?? false) ? 'yes' : '';
            $row = "| {$basename} | {$handles} | {$queued}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($listener);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     */
    private function buildCommands(array $commands): string
    {
        if (empty($commands)) {
            return '';
        }

        $count = count($commands);
        $hasSources = $this->hasSources($commands);
        $hasAliases = ! empty(array_filter($commands, fn (array $c): bool => ! empty($c['aliases'])));

        $header = '| Signature | Description';
        $divider = '|---|---';
        if ($hasAliases) {
            $header .= ' | Aliases';
            $divider .= '|---';
        }
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Artisan Commands ({$count})", '', $header.' |', $divider.'|'];

        foreach ($commands as $command) {
            $signature = (string) ($command['signature'] ?? '');
            $description = (string) ($command['description'] ?? '');
            $row = "| {$signature} | {$description}";
            if ($hasAliases) {
                $aliases = implode(', ', $command['aliases'] ?? []);
                $row .= " | {$aliases}";
            }
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($command);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $policies
     */
    private function buildPolicies(array $policies): string
    {
        if (empty($policies)) {
            return '';
        }

        $count = count($policies);
        $hasSources = $this->hasSources($policies);

        $header = '| Class | Model | Methods';
        $divider = '|---|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Policies ({$count})", '', $header.' |', $divider.'|'];

        foreach ($policies as $policy) {
            $basename = class_basename((string) ($policy['class'] ?? ''));
            $model = isset($policy['model']) ? class_basename((string) $policy['model']) : '';
            $methods = implode(', ', $policy['methods'] ?? []);
            $row = "| {$basename} | {$model} | {$methods}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($policy);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $observers
     */
    private function buildObservers(array $observers): string
    {
        if (empty($observers)) {
            return '';
        }

        $count = count($observers);
        $hasSources = $this->hasSources($observers);

        $header = '| Class | Model | Hooks | Queued';
        $divider = '|---|---|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Observers ({$count})", '', $header.' |', $divider.'|'];

        foreach ($observers as $observer) {
            $basename = class_basename((string) ($observer['class'] ?? ''));
            $model = isset($observer['model']) ? class_basename((string) $observer['model']) : '';
            $hooks = implode(', ', $observer['hooks'] ?? []);
            $queued = (bool) ($observer['queued'] ?? false) ? 'yes' : '';
            $row = "| {$basename} | {$model} | {$hooks} | {$queued}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($observer);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $enums
     */
    private function buildEnums(array $enums): string
    {
        if (empty($enums)) {
            return '';
        }

        $count = count($enums);
        $hasSources = $this->hasSources($enums);

        $header = '| Class | Type | Cases';
        $divider = '|---|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Enums ({$count})", '', $header.' |', $divider.'|'];

        foreach ($enums as $enum) {
            $basename = class_basename((string) ($enum['class'] ?? ''));
            $type = (string) ($enum['backing_type'] ?? 'pure');
            $caseLabels = array_map(
                fn (array $case): string => isset($case['value']) ? "{$case['name']}={$case['value']}" : $case['name'],
                $enum['cases'] ?? [],
            );
            $cases = implode(', ', $caseLabels);
            $row = "| {$basename} | {$type} | {$cases}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($enum);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tests
     */
    private function buildTests(array $tests): string
    {
        if (empty($tests)) {
            return '';
        }

        $count = count($tests);
        $hasSources = $this->hasSources($tests);

        $header = '| File | Type | Subject | Tests';
        $divider = '|---|---|---|---';
        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Tests ({$count})", '', $header.' |', $divider.'|'];

        foreach ($tests as $test) {
            $file = (string) ($test['file'] ?? '');
            $type = (string) ($test['type'] ?? '');
            $subject = isset($test['subject']) ? class_basename((string) $test['subject']) : '';
            $methods = implode(', ', (array) ($test['methods'] ?? []));
            $row = "| {$file} | {$type} | {$subject} | {$methods}";
            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($test);
            }
            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $scheduledTasks
     */
    private function buildScheduledTasks(array $scheduledTasks): string
    {
        if (empty($scheduledTasks)) {
            return '';
        }

        $count = count($scheduledTasks);
        $hasSources = $this->hasSources($scheduledTasks);
        $hasTimezone = ! empty(array_filter($scheduledTasks, fn (array $t): bool => isset($t['timezone'])));
        $hasNoOverlap = ! empty(array_filter($scheduledTasks, fn (array $t): bool => ! empty($t['without_overlapping'])));
        $hasBackground = ! empty(array_filter($scheduledTasks, fn (array $t): bool => ! empty($t['run_in_background'])));
        $hasInMaintenance = ! empty(array_filter($scheduledTasks, fn (array $t): bool => ! empty($t['even_in_maintenance'])));

        $header = '| Command | Schedule | Description';
        $divider = '|---|---|---';

        if ($hasTimezone) {
            $header .= ' | Timezone';
            $divider .= '|---';
        }

        if ($hasNoOverlap) {
            $header .= ' | No Overlap';
            $divider .= '|---';
        }

        if ($hasBackground) {
            $header .= ' | Background';
            $divider .= '|---';
        }

        if ($hasInMaintenance) {
            $header .= ' | In Maintenance';
            $divider .= '|---';
        }

        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Scheduled Tasks ({$count})", '', $header.' |', $divider.'|'];

        foreach ($scheduledTasks as $task) {
            $command = (string) ($task['command'] ?? '');
            // Use class_basename for FQCN-style job commands, otherwise use as-is.
            if (str_contains($command, '\\')) {
                $command = class_basename($command);
            }

            $schedule = (string) ($task['human_readable'] ?? $task['expression'] ?? '');
            $description = (string) ($task['description'] ?? '');

            $row = "| {$command} | {$schedule} | {$description}";

            if ($hasTimezone) {
                $row .= ' | '.((string) ($task['timezone'] ?? ''));
            }

            if ($hasNoOverlap) {
                $row .= ' | '.(! empty($task['without_overlapping']) ? 'yes' : '');
            }

            if ($hasBackground) {
                $row .= ' | '.(! empty($task['run_in_background']) ? 'yes' : '');
            }

            if ($hasInMaintenance) {
                $row .= ' | '.(! empty($task['even_in_maintenance']) ? 'yes' : '');
            }

            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($task);
            }

            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $middleware
     */
    private function buildMiddleware(array $middleware): string
    {
        if (empty($middleware)) {
            return '';
        }

        $count = count($middleware);
        $hasSources = $this->hasSources($middleware);
        $hasGroup = ! empty(array_filter($middleware, fn (array $m): bool => isset($m['scope']) && $m['scope'] === 'group'));

        $header = '| Alias | Class | Scope';
        $divider = '|---|---|---';

        if ($hasGroup) {
            $header .= ' | Group';
            $divider .= '|---';
        }

        if ($hasSources) {
            $header .= ' | Source';
            $divider .= '|---';
        }

        $lines = ["## Middleware ({$count})", '', $header.' |', $divider.'|'];

        foreach ($middleware as $item) {
            $alias = (string) ($item['alias'] ?? '');
            $class = class_basename((string) ($item['class'] ?? ''));
            $scope = (string) ($item['scope'] ?? '');

            $row = "| {$alias} | {$class} | {$scope}";

            if ($hasGroup) {
                $row .= ' | '.((string) ($item['group'] ?? ''));
            }

            if ($hasSources) {
                $row .= ' | '.$this->sourceCell($item);
            }

            $lines[] = $row.' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function hasSources(array $items): bool
    {
        foreach ($items as $item) {
            if (isset($item['source']['file'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function sourceCell(array $item): string
    {
        if (! isset($item['source']['file'])) {
            return '';
        }

        return $item['source']['file'].':'.($item['source']['line'] ?? '?');
    }

    /**
     * @param  list<array{name: string, optional: bool, constraint: string|null}>  $parameters
     */
    private function formatParameters(array $parameters): string
    {
        $parts = array_map(function (array $param): string {
            $label = $param['name'];

            if ($param['optional']) {
                $label .= '?';
            }

            if (isset($param['constraint'])) {
                $label .= ' ('.$param['constraint'].')';
            }

            return $label;
        }, $parameters);

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function formatControllerAction(array $route): string
    {
        $controller = $route['controller'] ?? null;
        $action = $route['action'] ?? null;

        if ($controller && $action) {
            return class_basename((string) $controller).'@'.$action;
        }

        if ($controller) {
            return class_basename((string) $controller);
        }

        return '';
    }

    private function writeToClaude(string $content, string $claudePath): bool
    {
        $wrapped = "<!-- necromancer:start -->\n{$content}\n<!-- necromancer:end -->";

        if (! file_exists($claudePath)) {
            if (file_put_contents($claudePath, $wrapped."\n") === false) {
                $this->error("Could not write to {$claudePath}.");

                return false;
            }

            return true;
        }

        $existing = file_get_contents($claudePath);

        if ($existing === false) {
            $this->error("Could not read {$claudePath}.");

            return false;
        }

        $hasStart = str_contains($existing, '<!-- necromancer:start -->');
        $hasEnd = str_contains($existing, '<!-- necromancer:end -->');

        if ($hasStart && ! $hasEnd) {
            $this->warn("Found orphaned <!-- necromancer:start --> in {$claudePath} without a closing <!-- necromancer:end -->. Please clean up the file manually before re-running.");

            return false;
        }

        $pattern = '/<!-- necromancer:start -->.*?<!-- necromancer:end -->/s';

        if (preg_match($pattern, $existing)) {
            $updated = preg_replace($pattern, $wrapped, $existing);
        } else {
            $updated = rtrim($existing)."\n\n".$wrapped."\n";
        }

        if (file_put_contents($claudePath, $updated) === false) {
            $this->error("Could not write to {$claudePath}.");

            return false;
        }

        return true;
    }

    private function resolveContextPath(): string
    {
        $boostPath = (string) config('necromancer.boost.context_path', base_path('.ai/context/necromancer.md'));
        $parentDir = dirname($boostPath);

        if (! is_dir($parentDir) && ! @mkdir($parentDir, 0755, true) && ! is_dir($parentDir)) {
            $fallback = (string) config('necromancer.output.context', base_path('NECROMANCER.md'));
            $this->warn("Could not create Boost context directory ({$parentDir}). Writing to {$fallback} instead.");

            return $this->isAbsolutePath($fallback) ? $fallback : base_path($fallback);
        }

        return $this->isAbsolutePath($boostPath) ? $boostPath : base_path($boostPath);
    }

    private function resolveSkillPath(): string
    {
        $skillPath = (string) config('necromancer.boost.skill_path', base_path('.ai/skills/necromancer.md'));
        $parentDir = dirname($skillPath);

        if (! is_dir($parentDir) && ! @mkdir($parentDir, 0755, true) && ! is_dir($parentDir)) {
            $fallback = (string) config('necromancer.output.context', base_path('NECROMANCER.md'));
            $this->warn("Could not create Boost skill directory ({$parentDir}). Writing Tier 2 to {$fallback} instead.");

            return $this->isAbsolutePath($fallback) ? $fallback : base_path($fallback);
        }

        return $this->isAbsolutePath($skillPath) ? $skillPath : base_path($skillPath);
    }
}
