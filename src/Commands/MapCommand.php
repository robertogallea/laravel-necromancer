<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class MapCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:map {--type= : Artifact type to display}';

    protected $description = 'Display the Necromancer application map from the manifest';

    public function handle(ManifestReader $reader): int
    {
        $path = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($path);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $this->warnIfStale($manifest);

        $artifacts = $manifest['artifacts'] ?? [];

        $availableTypes = array_keys(array_filter($artifacts, fn ($items) => ! empty($items)));

        $filterType = $this->option('type');

        if ($filterType !== null) {
            if (! in_array($filterType, $availableTypes, strict: true)) {
                $available = implode(', ', $availableTypes);
                $this->error("Unknown type \"{$filterType}\". Available types: {$available}");

                return self::FAILURE;
            }
            $artifacts = [$filterType => $artifacts[$filterType]];
        }

        foreach ($artifacts as $type => $items) {
            if (empty($items)) {
                continue;
            }

            $this->line(ucfirst($type));

            foreach ($items as $item) {
                $this->line('  '.$this->formatArtifact($type, $item));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatArtifact(string $type, array $item): string
    {
        return match ($type) {
            'routes' => $this->formatRoute($item),
            'models' => $this->formatModel($item),
            'form_requests' => $this->formatFormRequest($item),
            'jobs' => $this->formatJob($item),
            'events' => $this->formatEvent($item),
            'listeners' => $this->formatListener($item),
            'commands' => $this->formatCommand($item),
            default => (string) json_encode($item),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatRoute(array $item): string
    {
        $tokens = array_filter([
            $item['method'] ?? null,
            $item['uri'] ?? null,
            ($item['name'] ?? null) ?: null,
            ! empty($item['middleware']) ? implode(',', $item['middleware']) : null,
        ]);

        return implode('  ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatModel(array $item): string
    {
        $relationships = array_map(
            fn (array $rel) => $rel['type'].':'.class_basename((string) ($rel['related'] ?? '')),
            $item['relationships'] ?? [],
        );

        $observers = array_map(
            fn (string $o) => class_basename($o),
            $item['observers'] ?? [],
        );

        $globalScopes = array_map(
            fn (string $s) => class_basename($s),
            $item['global_scopes'] ?? [],
        );

        $tokens = array_filter([
            class_basename((string) ($item['class'] ?? '')),
            $item['table'] ?? null,
            ! empty($relationships) ? implode(',', $relationships) : null,
            ! empty($observers) ? 'observers:'.implode(',', $observers) : null,
            ! empty($globalScopes) ? 'scopes:'.implode(',', $globalScopes) : null,
        ]);

        return implode('  ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatFormRequest(array $item): string
    {
        $tokens = array_filter([
            class_basename((string) ($item['class'] ?? '')),
            ! empty($item['rules']) ? implode(', ', array_keys($item['rules'])) : null,
        ]);

        return implode('  ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatJob(array $item): string
    {
        $tokens = array_filter([
            class_basename((string) ($item['class'] ?? '')),
            ($item['queue'] ?? null) !== null ? 'queue:'.$item['queue'] : null,
            ($item['connection'] ?? null) !== null ? 'connection:'.$item['connection'] : null,
            ($item['tries'] ?? null) !== null ? 'tries:'.$item['tries'] : null,
            ! empty($item['backoff']) ? 'backoff:'.implode(',', (array) $item['backoff']) : null,
        ]);

        return implode('  ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatEvent(array $item): string
    {
        $listeners = array_map(
            fn (string $l) => class_basename($l),
            $item['listeners'] ?? [],
        );

        $tokens = array_filter([
            class_basename((string) ($item['class'] ?? '')),
            ! empty($listeners) ? implode(',', $listeners) : null,
        ]);

        return implode('  ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatListener(array $item): string
    {
        $handles = array_map(
            fn (string $e) => class_basename($e),
            $item['handles'] ?? [],
        );

        $tokens = array_filter([
            class_basename((string) ($item['class'] ?? '')),
            ! empty($handles) ? implode(',', $handles) : null,
            ($item['queued'] ?? false) === true ? 'queued' : null,
        ]);

        return implode('  ', $tokens);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatCommand(array $item): string
    {
        $tokens = array_filter([
            $item['signature'] ?? null,
            ($item['description'] ?? null) ?: null,
            ! empty($item['aliases']) ? 'aliases:'.implode(',', $item['aliases']) : null,
        ]);

        return implode('  ', $tokens);
    }
}
