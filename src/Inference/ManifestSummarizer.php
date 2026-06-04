<?php

declare(strict_types=1);

namespace LaravelNecromancer\Inference;

final class ManifestSummarizer
{
    private const ROUTE_CAP = 30;

    private const MODEL_CAP = 20;

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function summarize(array $manifest): string
    {
        $meta = $manifest['meta'] ?? [];
        $artifacts = $manifest['artifacts'] ?? [];

        $sections = [];

        $appName = (string) ($meta['app_name'] ?? 'Unknown');
        $sections[] = "App: {$appName}";

        $routes = (array) ($artifacts['routes'] ?? []);
        if (! empty($routes)) {
            $sections[] = $this->summarizeRoutes($routes);
        }

        $models = (array) ($artifacts['models'] ?? []);
        if (! empty($models)) {
            $sections[] = $this->summarizeModels($models);
        }

        $jobs = (array) ($artifacts['jobs'] ?? []);
        if (! empty($jobs)) {
            $sections[] = $this->summarizeJobs($jobs);
        }

        $events = (array) ($artifacts['events'] ?? []);
        if (! empty($events)) {
            $sections[] = $this->summarizeEvents($events);
        }

        $policies = (array) ($artifacts['policies'] ?? []);
        if (! empty($policies)) {
            $sections[] = $this->summarizePolicies($policies);
        }

        $commands = (array) ($artifacts['commands'] ?? []);
        if (! empty($commands)) {
            $sections[] = $this->summarizeCommands($commands);
        }

        return implode("\n", $sections);
    }

    /** @param list<array<string, mixed>> $routes */
    private function summarizeRoutes(array $routes): string
    {
        $total = count($routes);
        $capped = array_slice($routes, 0, self::ROUTE_CAP);

        $parts = array_map(function (array $r): string {
            $name = (string) ($r['name'] ?? '');
            $method = (string) ($r['method'] ?? '');
            $uri = (string) ($r['uri'] ?? '');
            $middleware = implode(',', (array) ($r['middleware'] ?? []));
            $mw = $middleware !== '' ? " [{$middleware}]" : '';

            return $name !== '' ? "{$name} {$method} {$uri}{$mw}" : "{$method} {$uri}{$mw}";
        }, $capped);

        $line = "Routes ({$total}): ".implode(', ', $parts);

        if ($total > self::ROUTE_CAP) {
            $remaining = $total - self::ROUTE_CAP;
            $line .= " ({$remaining} more not shown)";
        }

        return $line;
    }

    /** @param list<array<string, mixed>> $models */
    private function summarizeModels(array $models): string
    {
        $total = count($models);
        $capped = array_slice($models, 0, self::MODEL_CAP);

        $parts = array_map(function (array $m): string {
            $name = class_basename((string) ($m['class'] ?? ''));
            $table = (string) ($m['table'] ?? '');
            $rels = array_map(
                fn (array $r) => ($r['type'] ?? '').' '.class_basename((string) ($r['related'] ?? '')),
                (array) ($m['relationships'] ?? []),
            );
            $relStr = ! empty($rels) ? ' ('.implode(', ', $rels).')' : '';

            return "{$name} table={$table}{$relStr}";
        }, $capped);

        $line = "Models ({$total}): ".implode('; ', $parts);

        if ($total > self::MODEL_CAP) {
            $remaining = $total - self::MODEL_CAP;
            $line .= " ({$remaining} more not shown)";
        }

        return $line;
    }

    /** @param list<array<string, mixed>> $jobs */
    private function summarizeJobs(array $jobs): string
    {
        $parts = array_map(function (array $j): string {
            $name = class_basename((string) ($j['class'] ?? ''));
            $queue = isset($j['queue']) ? " queue={$j['queue']}" : '';
            $tries = isset($j['tries']) ? " tries={$j['tries']}" : '';

            return "{$name}{$queue}{$tries}";
        }, $jobs);

        return 'Jobs ('.count($jobs).'): '.implode('; ', $parts);
    }

    /** @param list<array<string, mixed>> $events */
    private function summarizeEvents(array $events): string
    {
        $parts = array_map(function (array $e): string {
            $name = class_basename((string) ($e['class'] ?? ''));
            $listeners = array_map(
                fn (string $l) => class_basename($l),
                (array) ($e['listeners'] ?? []),
            );
            $ls = ! empty($listeners) ? ' → '.implode(', ', $listeners) : '';

            return "{$name}{$ls}";
        }, $events);

        return 'Events ('.count($events).'): '.implode('; ', $parts);
    }

    /** @param list<array<string, mixed>> $policies */
    private function summarizePolicies(array $policies): string
    {
        $parts = array_map(function (array $p): string {
            $model = class_basename((string) ($p['model'] ?? ''));
            $policy = class_basename((string) ($p['class'] ?? ''));

            return "{$model}→{$policy}";
        }, $policies);

        return 'Policies ('.count($policies).'): '.implode(', ', $parts);
    }

    /** @param list<array<string, mixed>> $commands */
    private function summarizeCommands(array $commands): string
    {
        $parts = array_map(
            fn (array $c) => (string) ($c['signature'] ?? ''),
            $commands,
        );

        return 'Commands ('.count($commands).'): '.implode('; ', $parts);
    }
}
