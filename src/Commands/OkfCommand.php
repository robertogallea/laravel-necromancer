<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;
use LaravelNecromancer\Okf\BundleExporter;

final class OkfCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:okf
        {--output=       : Override the OKF bundle output directory}
        {--allow-stale   : Export even if the manifest appears stale}
        {--allow-partial : Export even if the manifest scope is partial}';

    protected $description = 'Export the manifest as a deterministic OKF Knowledge Bundle';

    public function handle(ManifestReader $reader, BundleExporter $exporter): int
    {
        $path = $this->resolveManifestPath();

        try {
            $manifest = $reader->read($path);
        } catch (ManifestNotFoundException) {
            $this->error('Necromancer manifest not found. Run necromancer:scan first.');

            return self::FAILURE;
        }

        $result = $exporter->export(
            manifest: $manifest,
            outputPath: $this->resolveOutputPath(),
            stale: $this->isStale($manifest),
            allowStale: (bool) $this->option('allow-stale'),
            allowPartial: (bool) $this->option('allow-partial'),
        );

        if (! $result->successful) {
            $this->error($result->error ?? 'OKF export failed.');

            return self::FAILURE;
        }

        $this->info("Written {$result->artifactCount} artifact concept(s) to {$result->outputPath}.");

        return self::SUCCESS;
    }

    private function resolveOutputPath(): string
    {
        $override = $this->option('output');

        $path = is_string($override) && $override !== ''
            ? $override
            : (string) config('necromancer.okf.output', base_path('okf'));

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }
}
