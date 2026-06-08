<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ScanManifest;

final class ScanCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:scan
        {--output=        : Write the manifest to this path instead of the configured default}
        {--only=          : Comma-separated artifact types to collect (routes,models,form_requests,jobs,events,listeners,commands,policies,enums,tests,observers,scheduled_tasks,middleware,livewire_components,gates,mailables,validation_rules,service_providers)}
        {--diff           : Show changes since the last manifest without writing a new file}
        {--fail-on-drift  : Exit non-zero when --diff detects any changes (for CI use)}';

    protected $description = 'Scan the Laravel application and write the Necromancer manifest';

    public function handle(ScanManifest $manifest, ManifestReader $reader): int
    {
        if ($this->option('diff')) {
            return $this->showDiff($manifest, $reader);
        }

        $path = $this->resolveOutputPath();

        if (! $this->canWriteManifest($path)) {
            $this->error("Unable to write Necromancer manifest to {$path}.");

            return self::FAILURE;
        }

        if (@file_put_contents($path, $manifest->toJson(only: $this->parseOnly()).PHP_EOL) === false) {
            $this->error("Unable to write Necromancer manifest to {$path}.");

            return self::FAILURE;
        }

        $this->info("Necromancer manifest written to {$path}");

        return self::SUCCESS;
    }

    private function showDiff(ScanManifest $manifest, ManifestReader $reader): int
    {
        $path = $this->resolveOutputPath();

        try {
            $old = $reader->read($path);
        } catch (ManifestNotFoundException) {
            $this->error("No existing manifest found at {$path}. Run necromancer:scan first.");

            return self::FAILURE;
        }

        $new = $manifest->buildPayload(only: $this->parseOnly());

        $oldArtifacts = is_array($old['artifacts']) ? $old['artifacts'] : [];
        $newArtifacts = is_array($new['artifacts']) ? $new['artifacts'] : [];

        $allTypes = array_unique(array_merge(array_keys($oldArtifacts), array_keys($newArtifacts)));
        sort($allTypes);

        $changedTypes = [];

        foreach ($allTypes as $type) {
            $oldKeys = array_map(
                fn (array $item): string => $this->artifactKey($type, $item),
                array_map(fn ($item): array => (array) $item, (array) ($oldArtifacts[$type] ?? [])),
            );
            $newKeys = array_map(
                fn (array $item): string => $this->artifactKey($type, $item),
                array_map(fn ($item): array => (array) $item, (array) ($newArtifacts[$type] ?? [])),
            );

            $added = array_values(array_diff($newKeys, $oldKeys));
            $removed = array_values(array_diff($oldKeys, $newKeys));

            if ($added !== [] || $removed !== []) {
                $changedTypes[$type] = ['added' => $added, 'removed' => $removed];
            }
        }

        if ($changedTypes === []) {
            $this->info('No changes detected.');

            return self::SUCCESS;
        }

        foreach ($changedTypes as $type => $changes) {
            $addCount = count($changes['added']);
            $removeCount = count($changes['removed']);
            $this->line('');
            $this->line("<fg=yellow>{$type}</> (+{$addCount} / -{$removeCount})");

            foreach ($changes['added'] as $key) {
                $this->line("  <fg=green>+</> {$key}");
            }

            foreach ($changes['removed'] as $key) {
                $this->line("  <fg=red>-</> {$key}");
            }
        }

        $total = array_sum(array_map(
            fn (array $c): int => count($c['added']) + count($c['removed']),
            $changedTypes,
        ));

        $this->line('');
        $this->line("{$total} change(s) across ".count($changedTypes).' type(s).');

        if ($this->option('fail-on-drift')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function artifactKey(string $type, array $item): string
    {
        if ($type === 'routes') {
            return (string) ($item['method'] ?? '').':'.($item['uri'] ?? '');
        }

        if ($type === 'tests') {
            return (string) ($item['file'] ?? json_encode($item));
        }

        return (string) ($item['class'] ?? $item['signature'] ?? json_encode($item));
    }

    private function resolveOutputPath(): string
    {
        $option = $this->option('output');
        $path = is_string($option) && $option !== ''
            ? $option
            : (string) config('necromancer.output.manifest', base_path('necromancer.json'));

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function canWriteManifest(string $path): bool
    {
        $directory = dirname($path);

        return is_dir($directory)
            && is_writable($directory)
            && ! is_dir($path);
    }

    /**
     * @return list<string>
     */
    private function parseOnly(): array
    {
        $option = $this->option('only');

        if (! is_string($option) || $option === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $option))));
    }
}
