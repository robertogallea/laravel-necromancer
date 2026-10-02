<?php

declare(strict_types=1);

namespace LaravelNecromancer\Relationships;

use LaravelNecromancer\Manifest\ArtifactId;

/**
 * Finds the Affected Tests of a set of changed artifacts by running one
 * Impact per start and keeping its `tests` nodes. Tests stay Boundary
 * Nodes (docs/adr/0022), so a test is only ever reached through the
 * tested_by Relationship of something the change reaches. Framework-free,
 * so the CLI and a future MCP tool share it.
 */
final readonly class AffectedTestFinder
{
    public function __construct(
        private ImpactAnalyzer $analyzer = new ImpactAnalyzer,
    ) {}

    /**
     * Maps changed file paths to the artifacts whose `source.file` they
     * are, by exact, case-sensitive comparison after stripping a leading
     * `./` and making a path under $basePath relative. Blank lines and
     * duplicates are skipped. Starts come in input order, then canonical
     * artifact order within one file.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $paths
     * @return array{starts: list<string>, unmapped: list<string>}
     */
    public function startsForPaths(array $manifest, array $paths, string $basePath): array
    {
        $byFile = [];

        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                $file = $artifact['source']['file'] ?? null;

                if (is_string($file) && is_string($artifact['id'] ?? null)) {
                    $byFile[$file][] = $artifact['id'];
                }
            }
        }

        $prefix = rtrim($basePath, '/').'/';
        $seen = [];
        $starts = [];
        $unmapped = [];

        foreach ($paths as $path) {
            $path = trim($path);

            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }

            while (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }

            if ($path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;

            if (! isset($byFile[$path])) {
                $unmapped[] = $path;

                continue;
            }

            foreach ($byFile[$path] as $id) {
                $starts[] = $id;
            }
        }

        return ['starts' => array_values(array_unique($starts)), 'unmapped' => $unmapped];
    }

    /**
     * Every Affected Test of the starts, each once at its smallest
     * distance, sorted by distance, then by start, then by canonical Impact
     * order. A start that is itself a test is affected at distance
     * 0 and is not walked from.
     *
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $starts
     * @return list<AffectedTest>
     */
    public function find(array $manifest, array $starts, int $depth): array
    {
        $files = [];

        foreach ((array) ($manifest['artifacts']['tests'] ?? []) as $test) {
            if (is_string($test['id'] ?? null) && is_string($test['file'] ?? null)) {
                $files[$test['id']] = $test['file'];
            }
        }

        /** @var array<string, AffectedTest> $found */
        $found = [];

        foreach ($starts as $start) {
            if (isset($files[$start])) {
                $found[$start] = new AffectedTest($start, $files[$start], 0, $start);

                continue;
            }

            $impact = $this->analyzer->analyze($manifest, $start, $depth);

            foreach ($impact->nodesOfTypes(['tests']) as $node) {
                if ($node->via->type !== RelationshipType::TestedBy) {
                    continue;
                }

                if (isset($found[$node->id]) && $found[$node->id]->distance <= $node->distance) {
                    continue;
                }

                unset($found[$node->id]);

                $found[$node->id] = new AffectedTest(
                    $node->id,
                    $files[$node->id] ?? $node->id,
                    $node->distance,
                    $start,
                    $node,
                    $impact->label($node->viaFrom),
                );
            }
        }

        $tests = array_values($found);
        usort($tests, fn (AffectedTest $a, AffectedTest $b): int => $a->distance <=> $b->distance);

        return $tests;
    }
}
