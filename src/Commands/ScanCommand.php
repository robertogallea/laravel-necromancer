<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Diff\ManifestDiffer;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Manifest\ScanManifest;

final class ScanCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:scan
        {--output=        : Write the manifest to this path instead of the configured default}
        {--only=          : Comma-separated artifact types to collect (routes,controllers,models,form_requests,actions,jobs,events,listeners,commands,policies,enums,tests,observers,scheduled_tasks,middleware,livewire_components,gates,mailables,validation_rules,service_providers)}
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

        try {
            $payload = $manifest->toJson(only: $this->parseOnly()).PHP_EOL;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (@file_put_contents($path, $payload) === false) {
            $this->error("Unable to write Necromancer manifest to {$path}.");

            return self::FAILURE;
        }

        foreach (array_unique($manifest->diagnostics()) as $diagnostic) {
            $this->warn($diagnostic);
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

        try {
            $new = $manifest->buildPayload(only: $this->parseOnly());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Round-trip the fresh payload through JSON so it compares like the decoded manifest on disk.
        $new = (array) json_decode(json_encode($new, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        try {
            $diff = (new ManifestDiffer)->diff(
                is_array($old['artifacts'] ?? null) ? $old['artifacts'] : [],
                is_array($new['artifacts'] ?? null) ? $new['artifacts'] : [],
            );
        } catch (InvalidArgumentException $exception) {
            $this->error("Manifest contains an artifact with no canonical key: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $storedHash = $old['meta']['content_hash'] ?? null;
        $isCurrent = is_string($storedHash) && $storedHash !== ''
            ? $storedHash === ($new['meta']['content_hash'] ?? null)
            : $diff->isEmpty();

        if ($isCurrent) {
            $this->info('No changes detected.');

            return self::SUCCESS;
        }

        if ($diff->isEmpty()) {
            $this->warn('Manifest content hash differs (scan scope or schema) but no artifact-level changes were found.');

            return $this->option('fail-on-drift') ? self::FAILURE : self::SUCCESS;
        }

        $types = array_unique(array_merge(array_keys($diff->added), array_keys($diff->removed), array_keys($diff->changed)));
        sort($types);

        foreach ($types as $type) {
            $added = $diff->added[$type] ?? [];
            $removed = $diff->removed[$type] ?? [];
            $changed = $diff->changed[$type] ?? [];

            $this->line('');
            $this->line("<fg=yellow>{$type}</> (+".count($added).' / -'.count($removed).' / ~'.count($changed).')');

            foreach ($added as $artifact) {
                $this->line("  <fg=green>+</> {$artifact['id']}");
            }

            foreach ($removed as $artifact) {
                $this->line("  <fg=red>-</> {$artifact['id']}");
            }

            foreach ($changed as $change) {
                $this->line("  <fg=cyan>~</> {$change['to']['id']}");
            }
        }

        $total = $diff->totalAdditions() + $diff->totalRemovals() + $diff->totalChanges();

        $this->line('');
        $this->line("{$total} change(s) across ".count($types).' type(s).');

        if ($this->option('fail-on-drift')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
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
