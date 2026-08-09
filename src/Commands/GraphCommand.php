<?php

declare(strict_types=1);

namespace LaravelNecromancer\Commands;

use Illuminate\Console\Command;
use LaravelNecromancer\Commands\Concerns\ReadsManifest;
use LaravelNecromancer\Graph\GraphExporter;
use LaravelNecromancer\Manifest\ManifestNotFoundException;
use LaravelNecromancer\Manifest\ManifestReader;

final class GraphCommand extends Command
{
    use ReadsManifest;

    protected $signature = 'necromancer:graph
        {--output=       : Override the graph output directory}
        {--allow-stale   : Build the graph even if the manifest appears stale}
        {--allow-partial : Build the graph even if the manifest scope is partial}';

    protected $description = 'Build a deterministic Artifact Graph (nodes and relationships) from the manifest';

    public function handle(ManifestReader $reader, GraphExporter $exporter): int
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
            $this->error($result->error ?? 'Graph build failed.');

            return self::FAILURE;
        }

        $this->info("Written {$result->nodeCount} node(s) to {$result->outputPath}.");
        $this->line('Serve the output directory over HTTP to view graph.html (opening it via file:// will fail to load graph.json due to CORS).');

        return self::SUCCESS;
    }

    private function resolveOutputPath(): string
    {
        $override = $this->option('output');

        $path = is_string($override) && $override !== ''
            ? $override
            : (string) config('necromancer.output.graph', base_path('necromancer-graph'));

        return $this->isAbsolutePath($path) ? $path : base_path($path);
    }
}
