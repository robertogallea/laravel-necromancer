<?php

declare(strict_types=1);

namespace LaravelNecromancer\Manifest;

use LaravelNecromancer\Relationships\AffectedTest;
use LaravelNecromancer\Relationships\AffectedTestFinder;
use LaravelNecromancer\Relationships\ImpactAnalyzer;
use LaravelNecromancer\Relationships\ImpactDirection;
use LaravelNecromancer\Relationships\Relationship;
use LaravelNecromancer\Relationships\RelationshipResolver;
use stdClass;

/**
 * The graph queries behind get_artifact, get_relationships, get_impact,
 * and get_affected_tests, shared by the MCP server and the benchmark's
 * necromancer-mcp-graph condition. A stale or partial manifest is
 * answered with warnings rather than refused (docs/adr/0023); every other
 * failure is an error result.
 */
final readonly class GraphQueryService
{
    /**
     * The deepest walk returned to an agent: the answer lands in its
     * context window (docs/adr/0023).
     */
    public const MAX_DEPTH = 3;

    public function __construct(
        private string $manifestPath,
        private string $basePath,
        private ManifestReader $reader = new ManifestReader,
        private ImpactAnalyzer $analyzer = new ImpactAnalyzer,
        private RelationshipResolver $resolver = new RelationshipResolver,
        private AffectedTestFinder $finder = new AffectedTestFinder,
    ) {}

    /**
     * One artifact's full manifest payload, by Artifact ID or FQCN.
     */
    public function artifact(string $input): GraphQueryResult
    {
        if (ImpactAnalyzer::isConceptId($input)) {
            return GraphQueryResult::error('not_an_artifact', "'{$input}' is a Domain, Flow, or ADR, not a collected artifact. Use get_relationships to list what belongs to or references it.");
        }

        $manifest = $this->loadManifest();

        if ($manifest instanceof GraphQueryResult) {
            return $manifest;
        }

        $id = $this->resolveStart($manifest, $input);

        if ($id instanceof GraphQueryResult) {
            return $id;
        }

        return GraphQueryResult::success([
            'artifact' => $this->payload($manifest, $id),
            'warnings' => $this->manifestWarnings($manifest),
        ]);
    }

    /**
     * Every Relationship the start takes part in, in canonical order, with
     * its direction relative to the start.
     */
    public function relationships(string $input): GraphQueryResult
    {
        $manifest = $this->loadManifest();

        if ($manifest instanceof GraphQueryResult) {
            return $manifest;
        }

        $id = $this->resolveStart($manifest, $input);

        if ($id instanceof GraphQueryResult) {
            return $id;
        }

        $relationships = array_values(array_filter(
            $this->resolver->resolve($manifest),
            fn (Relationship $relationship): bool => $relationship->from === $id || $relationship->to === $id,
        ));

        return GraphQueryResult::success([
            'artifact' => $id,
            'relationships' => array_map(fn (Relationship $relationship): array => $this->relationshipEntry($relationship, $id), $relationships),
            'warnings' => $this->manifestWarnings($manifest),
        ]);
    }

    /**
     * The Impact of the start, filtered to $types (a comma-separated
     * string or a list), in the necromancer:impact --json shape.
     */
    public function impact(string $input, mixed $depth = null, mixed $types = null): GraphQueryResult
    {
        $types = $this->types($types);
        $unknown = array_diff($types, ImpactAnalyzer::nodeTypes());

        if ($unknown !== []) {
            return GraphQueryResult::error('invalid_type', 'Unknown types value(s): '.implode(', ', $unknown).'. Supported: '.implode(', ', ImpactAnalyzer::nodeTypes()).'.');
        }

        $manifest = $this->loadManifest();

        if ($manifest instanceof GraphQueryResult) {
            return $manifest;
        }

        $start = $this->resolveStart($manifest, $input);

        if ($start instanceof GraphQueryResult) {
            return $start;
        }

        $warnings = $this->manifestWarnings($manifest);
        $depth = $this->clampDepth('get_impact', $depth, 1, $warnings);

        $impact = $this->analyzer->analyze($manifest, $start, $depth);

        return GraphQueryResult::success([
            'start' => $impact->start,
            'depth' => $impact->depth,
            'nodes' => $impact->nodesOfTypes($types),
            'warnings' => $warnings,
        ]);
    }

    /**
     * The tests affected by exactly one of a changed artifact or a list of
     * changed paths, in the necromancer:affected-tests --json sections.
     */
    public function affectedTests(mixed $artifact = null, mixed $paths = null, mixed $depth = null): GraphQueryResult
    {
        if (($artifact !== null) === ($paths !== null)) {
            return GraphQueryResult::error('invalid_input', 'Pass exactly one of artifact or paths.');
        }

        $manifest = $this->loadManifest();

        if ($manifest instanceof GraphQueryResult) {
            return $manifest;
        }

        $unmapped = [];

        if ($paths !== null) {
            $paths = array_map(fn (mixed $path): string => (string) $path, (array) $paths);
            ['starts' => $starts, 'unmapped' => $unmapped] = $this->finder->startsForPaths($manifest, $paths, $this->basePath);
        } else {
            $start = $this->resolveStart($manifest, (string) $artifact);

            if ($start instanceof GraphQueryResult) {
                return $start;
            }

            $starts = [$start];
        }

        $stale = (new ManifestStaleness($this->basePath))->isStale($manifest);
        $warnings = $this->manifestWarnings($manifest, $stale);

        if ($stale && $unmapped !== []) {
            $warnings[] = count($unmapped).' unmapped path(s) may be files created or moved since the scan. Run `php artisan necromancer:scan` to map them.';
        }

        $depth = $this->clampDepth('get_affected_tests', $depth, 2, $warnings);
        $tests = $this->finder->find($manifest, $starts, $depth);

        return GraphQueryResult::success([
            'directly_affected' => array_values(array_filter($tests, fn (AffectedTest $test): bool => $test->directlyAffected())),
            'indirectly_affected' => array_values(array_filter($tests, fn (AffectedTest $test): bool => ! $test->directlyAffected())),
            'unmapped' => $unmapped,
            'depth' => $depth,
            'warnings' => $warnings,
        ]);
    }

    /**
     * The manifest, or a manifest_not_found error when it is missing or
     * predates schema v1.
     *
     * @return array<string, mixed>|GraphQueryResult
     */
    private function loadManifest(): array|GraphQueryResult
    {
        try {
            return $this->reader->read($this->manifestPath);
        } catch (ManifestNotFoundException) {
            return GraphQueryResult::error('manifest_not_found', 'No current Necromancer manifest was found (it is missing or predates schema v1). Run `php artisan necromancer:scan` to generate it.');
        }
    }

    /**
     * The single Artifact ID (or referenced Domain/Flow/ADR ID) the input
     * denotes, or an ambiguous/not_found error.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function resolveStart(array $manifest, string $input): string|GraphQueryResult
    {
        $candidates = $this->analyzer->startCandidates($manifest, $input);

        if ($candidates === []) {
            return GraphQueryResult::error('not_found', "No artifact matches '{$input}'. Pass an exact Artifact ID, a fully-qualified class name, or a referenced domain:/flow:/adr: ID.");
        }

        if (count($candidates) > 1) {
            return GraphQueryResult::error('ambiguous', "'{$input}' matches several artifacts. Pass one of the candidate Artifact IDs instead.", ['candidates' => $candidates]);
        }

        return $candidates[0];
    }

    /**
     * The requested depth (or $default) clamped to 1–MAX_DEPTH, adding a
     * warning to $warnings when it was clamped.
     *
     * @param  list<string>  $warnings
     */
    private function clampDepth(string $tool, mixed $requested, int $default, array &$warnings): int
    {
        $depth = (int) ($requested ?? $default);
        $clamped = max(1, min(self::MAX_DEPTH, $depth));

        if ($clamped !== $depth) {
            $warnings[] = "Requested depth {$depth} was clamped to {$clamped}; {$tool} accepts a depth from 1 to ".self::MAX_DEPTH.'.';
        }

        return $clamped;
    }

    /**
     * One warning when the manifest appears stale, and one when its scope
     * is not complete. Pass $stale when it is already known, so the source
     * files are not hashed twice.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function manifestWarnings(array $manifest, ?bool $stale = null): array
    {
        $warnings = [];

        if ($stale ?? (new ManifestStaleness($this->basePath))->isStale($manifest)) {
            $warnings[] = 'Manifest may be stale — source files have changed since it was generated, so recent edits may be missing. Run `php artisan necromancer:scan` to refresh.';
        }

        $scope = is_array($manifest['meta']['scope'] ?? null) ? $manifest['meta']['scope'] : [];

        if (! (bool) ($scope['complete'] ?? false)) {
            $types = array_filter((array) ($scope['artifact_types'] ?? []), is_string(...));
            $covered = $types === [] ? 'did not cover every artifact type' : 'covered only '.implode(', ', $types);
            $warnings[] = "Manifest scope is partial — the scan {$covered}, so Relationships to other artifact types are missing. Run a full `php artisan necromancer:scan`.";
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function payload(array $manifest, string $id): array
    {
        foreach (ArtifactId::supportedTypes() as $type) {
            foreach ((array) ($manifest['artifacts'][$type] ?? []) as $artifact) {
                if (is_array($artifact) && ($artifact['id'] ?? null) === $id) {
                    return $artifact;
                }
            }
        }

        return [];
    }

    /**
     * The Relationship as serialized in graph.json (empty metadata as an
     * object), plus its direction relative to the start.
     *
     * @return array<string, mixed>
     */
    private function relationshipEntry(Relationship $relationship, string $id): array
    {
        $entry = $relationship->jsonSerialize();

        if ($entry['metadata'] === []) {
            $entry['metadata'] = new stdClass;
        }

        $entry['direction'] = ($relationship->from === $id ? ImpactDirection::Out : ImpactDirection::In)->value;

        return $entry;
    }

    /**
     * @return list<string>
     */
    private function types(mixed $types): array
    {
        $types = is_string($types) ? explode(',', $types) : (array) $types;

        return array_values(array_filter(
            array_map(fn (mixed $type): string => trim((string) $type), $types),
            fn (string $type): bool => $type !== '',
        ));
    }
}
