<?php

declare(strict_types=1);

namespace LaravelNecromancer\Graph;

use LaravelNecromancer\Okf\BundleSwap;
use LaravelNecromancer\Support\RecursivePathRemover;
use RuntimeException;
use Throwable;

/**
 * Projects a manifest into graph.json + graph.html and writes both
 * atomically: built in a temp directory first, then swapped into place via
 * LaravelNecromancer\Okf\BundleSwap (reused as-is — it already operates on
 * arbitrary directories, not just OKF bundles), so a failed run never
 * damages a previously-generated graph. Never rescans the application —
 * the manifest passed in is the sole input. Mirrors
 * LaravelNecromancer\Okf\BundleExporter's stale/partial gating so both
 * commands refuse the same unsafe input, though the two are otherwise
 * fully independent per ADR-0001.
 */
final readonly class GraphExporter
{
    public function __construct(
        private ArtifactGraphBuilder $builder = new ArtifactGraphBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function export(array $manifest, string $outputPath, bool $stale, bool $allowStale, bool $allowPartial): GraphExportResult
    {
        $scopeError = $this->validateScope($manifest, $stale, $allowStale, $allowPartial);

        if ($scopeError !== null) {
            return GraphExportResult::failure($scopeError);
        }

        $graph = $this->builder->build($manifest);

        try {
            $this->writeAtomically($outputPath, $graph);
        } catch (Throwable $e) {
            return GraphExportResult::failure("Failed to write the graph: {$e->getMessage()}");
        }

        return GraphExportResult::success($outputPath, count($graph->nodes));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateScope(array $manifest, bool $stale, bool $allowStale, bool $allowPartial): ?string
    {
        if ($stale && ! $allowStale) {
            return 'Manifest may be stale — source files have changed since it was generated. Run necromancer:scan to refresh, or pass --allow-stale to build the graph anyway.';
        }

        $scope = is_array($manifest['meta']['scope'] ?? null) ? $manifest['meta']['scope'] : [];
        $complete = (bool) ($scope['complete'] ?? false);

        if (! $complete && ! $allowPartial) {
            return 'Manifest scope is partial — it was produced by a scan that did not cover every artifact type. Run a full necromancer:scan, or pass --allow-partial to build the graph anyway.';
        }

        return null;
    }

    private function writeAtomically(string $outputPath, ArtifactGraph $graph): void
    {
        $tempPath = rtrim($outputPath, '/').'.tmp';
        RecursivePathRemover::remove($tempPath);

        if (! mkdir($tempPath, 0755, true) && ! is_dir($tempPath)) {
            throw new RuntimeException("Unable to create temporary graph directory at {$tempPath}.");
        }

        $json = json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($tempPath.'/graph.json', $json) === false) {
            RecursivePathRemover::remove($tempPath);

            throw new RuntimeException('Unable to write graph.json.');
        }

        if (file_put_contents($tempPath.'/graph.html', GraphHtmlTemplate::render()) === false) {
            RecursivePathRemover::remove($tempPath);

            throw new RuntimeException('Unable to write graph.html.');
        }

        try {
            (new BundleSwap)->swap($tempPath, $outputPath);
        } catch (RuntimeException $e) {
            RecursivePathRemover::remove($tempPath);

            throw $e;
        }
    }
}
